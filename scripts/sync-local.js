'use strict';
/**
 * Run on the development computer: node scripts/sync-local.js [--dry-run]
 * Copy source into the existing local container, keeping runtime data intact.
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
  'app/database/migrations',
  'app/database/reference.sql',
  'public',
  'scripts',
  'tests',
  'docs',
  'README.md',
  'Dockerfile',
  '.dockerignore',
  'composer.json',
  'composer.lock',
];
const excludedDirectories = ['public/assets/products', 'public/uploads'];
const files = [];

function collect(relative) {
  const name = path.posix.basename(relative);
  if (
    excludedDirectories.some((dir) => relative === dir || relative.startsWith(dir + '/')) ||
    /\.local\.php$/i.test(name) ||
    /^\.env(?:\.|$)/i.test(name)
  )
    return;
  const absolute = path.join(root, relative);
  if (!fs.existsSync(absolute)) return;
  const stat = fs.lstatSync(absolute);
  if (stat.isSymbolicLink()) throw Error('Source links must be handled explicitly: ' + relative);
  if (stat.isDirectory()) {
    for (const child of fs.readdirSync(absolute).sort()) collect(relative + '/' + child);
  } else if (stat.isFile()) files.push(relative);
}

const docker = (args) => execFileSync('docker', args, { encoding: 'utf8', windowsHide: true });

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
    state.mounts.some((mount) =>
      files.some((file) => {
        const target = destination + '/' + file;
        return (
          target === mount.Destination ||
          target.startsWith(mount.Destination.replace(/\/$/, '') + '/')
        );
      }),
    )
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
    console.log(`Synced ${files.length} source files. Refresh the website with Ctrl + F5.`);
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
