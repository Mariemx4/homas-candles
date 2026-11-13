const { spawn } = require('node:child_process');
const { mkdtempSync, readdirSync, readFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve, basename, sep } = require('node:path');
const { randomBytes } = require('node:crypto');
const assert = require('node:assert/strict');
const net = require('node:net');

(async () => {
  const directory = mkdtempSync(join(tmpdir(), 'homas-payment-upload-'));
  const token = randomBytes(32).toString('hex');
  const port = await new Promise((done) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => {
      const value = socket.address().port;
      socket.close(() => done(value));
    });
  });
  const phpOptions =
    process.platform === 'win32'
      ? ['-d', 'extension_dir=C:/php/ext', '-d', 'extension=fileinfo']
      : [];
  const server = spawn(
    process.env.PHP_BINARY || 'php',
    [
      ...phpOptions,
      '-d',
      'upload_max_filesize=6M',
      '-d',
      'post_max_size=7M',
      '-S',
      `127.0.0.1:${port}`,
      resolve(__dirname, '../Support/payment-upload-endpoint.php'),
    ],
    {
      env: {
        ...process.env,
        HOMAS_UPLOAD_TEST_TOKEN: token,
        HOMAS_UPLOAD_TEST_DIRECTORY: directory,
      },
      stdio: ['ignore', 'ignore', 'pipe'],
    },
  );
  let stderr = '';
  server.stderr.on('data', (data) => {
    stderr += data;
  });
  server.on('error', (error) => {
    stderr += error.message;
  });
  const url = `http://127.0.0.1:${port}/`;
  const upload = async (bytes, name, type) => {
    const data = new FormData();
    data.append('evidence', new Blob([bytes], { type }), name);
    const response = await fetch(url, {
      method: 'POST',
      headers: { 'X-Upload-Test-Token': token },
      body: data,
    });
    return response.json();
  };
  try {
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
      try {
        await fetch(url);
        ready = true;
        break;
      } catch {
        await new Promise((done) => setTimeout(done, 100));
      }
    }
    assert(ready, `PHP upload server could not start: ${stderr}`);
    assert.equal((await fetch(url)).status, 403, 'Test endpoint requires runner capability');
    const png = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a4X8AAAAASUVORK5CYII=',
      'base64',
    );
    const first = await upload(png, 'customer-order-1.php', 'application/x-php');
    assert.equal(first.success, true);
    assert.match(first.path, /^[a-f0-9]{40}\.png$/);
    assert.deepEqual(readFileSync(join(directory, first.path)), png);
    const second = await upload(png, 'customer-order-1.png', 'image/png');
    assert.equal(second.success, true);
    assert.notEqual(first.path, second.path);
    assert.equal(
      (await upload(Buffer.from('<?php echo "unsafe";'), 'proof.png', 'image/png')).success,
      false,
    );
    assert.equal(
      (
        await upload(
          Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
          'proof.svg',
          'image/png',
        )
      ).success,
      false,
    );
    assert.equal(
      (await upload(Buffer.alloc(5 * 1024 * 1024 + 1, 65), 'large.png', 'image/png')).success,
      false,
    );
    assert.equal(
      (
        await upload(
          Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF'),
          'proof.pdf',
          'application/pdf',
        )
      ).success,
      true,
    );
    assert.equal(readdirSync(directory).length, 3, 'Rejected uploads leave no stored files');
    console.log(
      '[PASS] Genuine multipart uploads validate content/size, reject executable/SVG/oversized files, preserve supported PDFs, and use unpredictable content-based storage names.',
    );
  } finally {
    await new Promise((done) => {
      if (server.exitCode !== null) return done();
      server.once('exit', done);
      server.kill();
    });
    assert(
      resolve(directory).startsWith(resolve(tmpdir()) + sep) &&
        basename(directory).startsWith('homas-payment-upload-'),
      'Cleanup stays inside the generated upload-test directory',
    );
    rmSync(directory, { recursive: true, force: true });
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
