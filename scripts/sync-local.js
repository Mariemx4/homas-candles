'use strict';
/**
 * Run on the development computer: node scripts/sync-local.js [--dry-run]
 * Mirror source into the existing local container, keeping runtime data intact.
 * No packages, migrations, container recreation or database writes are involved.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const container = 'homas-test';
const destination = '/var/www/html';
const roots = [
  'src',
  'app/Bootstrap',
  'app/Views',
  'app/config',
  'app/translations',
  'app/database',
  'public',
  'scripts',
  'tests',
  'docs',
  'README.md',
  'Dockerfile',
  '.dockerignore',
  '.htaccess',
  'composer.json',
  'composer.lock',
];
const excludedDirectories = ['public/assets/products', 'public/uploads'];
const files = [];

function isExcluded(relative) {
  return excludedDirectories.some((entry) => relative === entry || relative.startsWith(entry + '/')) ||
    /(?:^|\/)\.env(?:\.|$)|\.local\.php$/i.test(relative);
}

function collect(relative) {
  if (isExcluded(relative)) return;
  const absolute = path.join(root, relative);
  if (!fs.existsSync(absolute)) return;
  const stat = fs.lstatSync(absolute);
  if (stat.isSymbolicLink()) throw Error('Source links must be handled explicitly: ' + relative);
  if (stat.isDirectory()) {
    for (const child of fs.readdirSync(absolute).sort()) collect(relative + '/' + child);
  } else if (stat.isFile()) files.push(relative);
}

const docker = (args) => execFileSync('docker', args, { encoding: 'utf8', windowsHide: true });

function obsoleteFiles(existing) {
  const current = new Set(files);
  return existing.filter((relative) => {
    if (
      path.posix.normalize(relative) !== relative || relative.startsWith('/') ||
      relative.split('/').includes('..') ||
      !roots.some((entry) => relative === entry || relative.startsWith(entry + '/')) ||
      isExcluded(relative)
    ) throw Error('Unexpected container source path: ' + relative);
    return !current.has(relative);
  });
}

function sync() {
  const args = process.argv.slice(2);
  if (args.some((arg) => arg !== '--dry-run'))
    throw Error('Usage: node scripts/sync-local.js [--dry-run]');
  roots.forEach(collect);
  if (!files.length) throw Error('No project source files found.');
  if (files.some((file) => file.startsWith('app/storage/') || /\.local\.php$/i.test(file))) {
    throw Error('Runtime data unexpectedly entered the source plan.');
  }
  if (args.includes('--dry-run')) {
    console.log(`Dry run: ${files.length} source files would sync to ${container}:${destination}.`);
    console.log(
      'Private configuration, runtime storage and uploaded product/payment files are excluded.',
    );
    return;
  }

  const state = JSON.parse(
    docker([
      'inspect',
      '--format',
      '{"running":{{.State.Running}},"mounts":{{json .Mounts}}}',
      container,
    ]),
  );
  if (!state.running) throw Error('Start Docker and the homas-test container first.');
  if (
    state.mounts.some((mount) => {
      if (mount.Destination.startsWith(destination + '/') &&
          isExcluded(mount.Destination.slice(destination.length + 1))) return false;
      return roots.some((entry) => {
        const target = destination + '/' + entry;
        return (
          target === mount.Destination ||
          target.startsWith(mount.Destination.replace(/\/$/, '') + '/') ||
          mount.Destination.startsWith(target + '/')
        );
      });
    })
  )
    throw Error(
      'This container uses mounted source files. Save changes in its mounted project instead.',
    );

  // Syncing PHP cannot install a newly required library. Stop before replacing
  // source if this container has not installed the same dependency lockfile.
  if (fs.existsSync(path.join(root, 'composer.lock'))) {
    const crypto = require('node:crypto');
    const expected = crypto
      .createHash('sha256')
      .update(fs.readFileSync(path.join(root, 'composer.lock')))
      .digest('hex');
    const installed = docker([
      'exec',
      container,
      'php',
      '-r',
      "echo is_file('/var/www/html/composer.lock') && is_file('/var/www/html/vendor/autoload.php') ? hash_file('sha256','/var/www/html/composer.lock') : '';",
    ]).trim();
    if (installed !== expected) {
      throw Error(
        'Composer dependencies changed. Rebuild the Docker image or install its locked dependencies before syncing source.',
      );
    }
  }
  try {
    docker(['exec', container, 'composer', '--version', '--no-interaction']);
  } catch {
    throw Error('The container needs the Composer CLI from the current Dockerfile before source sync.');
  }

  const stage = fs.mkdtempSync(path.join(os.tmpdir(), 'homas-source-sync-'));
  try {
    for (const relative of files) {
      const target = path.join(stage, relative);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.copyFileSync(path.join(root, relative), target);
    }
    // Keep a rollback copy of the same source areas before overwriting code.
    // These exclusions also apply to the backup, not just the new source plan.
    const backup =
      '/tmp/homas-source-before-sync-' + new Date().toISOString().replace(/[:.]/g, '-') + '.tar.gz';
    const existing = roots.filter((relative) => {
      try {
        docker(['exec', container, 'test', '-e', destination + '/' + relative]);
        return true;
      } catch {
        return false;
      }
    });
    // Enumerate only owned source areas. find does not follow symbolic links;
    // private settings and uploaded files are pruned before any traversal.
    const stale = obsoleteFiles(docker([
      'exec', container, 'find', ...existing.map((entry) => destination + '/' + entry),
      '(', '-name', '*.local.php', '-o', '-name', '.env', '-o', '-name', '.env.*',
      ...excludedDirectories.flatMap((entry) => ['-o', '-path', destination + '/' + entry]),
      ')', '-prune', '-o', '-type', 'f', '-printf', '%p\\0',
    ]).split('\0').filter(Boolean).map((file) => {
      if (!file.startsWith(destination + '/')) throw Error('Source path escaped the container root.');
      return file.slice(destination.length + 1);
    }));
    docker([
      'exec',
      container,
      'tar',
      '-czf',
      backup,
      '-C',
      destination,
      '--exclude=*.local.php',
      '--exclude=.env',
      '--exclude=.env.*',
      '--exclude=public/assets/products',
      '--exclude=public/uploads',
      ...existing,
    ]);
    docker(['cp', stage + path.sep + '.', container + ':' + destination + '/']);
    for (let index = 0; index < stale.length; index += 100) {
      docker(['exec', container, 'rm', '--', ...stale.slice(index, index + 100).map((entry) => destination + '/' + entry)]);
    }
    // Remove empty source directories left by moves; protected trees stay out.
    const directories = roots.filter((entry) => fs.statSync(path.join(root, entry)).isDirectory());
    docker([
      'exec', container, 'find', ...directories.map((entry) => destination + '/' + entry),
      '-depth', '-type', 'd',
      ...excludedDirectories.flatMap((entry) => ['!', '-path', destination + '/' + entry, '!', '-path', destination + '/' + entry + '/*']),
      '-empty', '-delete',
    ]);
    // Remove deleted classes from generated metadata without changing packages.
    docker(['exec', container, 'composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction', '--no-scripts']);
    console.log(`Synced ${files.length} source files. Refresh the website with Ctrl + F5.`);
    console.log(`Removed ${stale.length} obsolete source files.`);
    console.log('Previous source backup: ' + container + ':' + backup);
  } finally {
    const resolved = path.resolve(stage);
    if (
      path.dirname(resolved) !== path.resolve(os.tmpdir()) ||
      !path.basename(resolved).startsWith('homas-source-sync-')
    ) {
      throw Error('Unexpected temporary staging path.');
    }
    fs.rmSync(resolved, { recursive: true, force: true });
  }
}

try {
  sync();
} catch (error) {
  console.error('Code sync failed: ' + error.message);
  process.exitCode = 1;
}
