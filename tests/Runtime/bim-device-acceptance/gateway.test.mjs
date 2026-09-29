import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { mkdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { createServer, request } from 'node:http';
import { createServer as createPortReservation } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawn } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function listen(server) {
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return server.address().port;
}

async function freePort(excluded) {
  for (let attempt = 0; attempt < 20; attempt++) {
    const server = createPortReservation();
    const port = await listen(server);
    await new Promise((resolve) => server.close(resolve));
    if (!excluded.includes(port)) return port;
  }
  throw new Error('unique_test_port_unavailable');
}

function fetch(port, path, method = 'GET', body = null) {
  return new Promise((resolve, reject) => {
    const headers = body ? { 'Content-Length': body.length } : {};
    const req = request({ hostname: '127.0.0.1', port, path, method, headers, agent: false }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.once('error', reject);
      res.once('end', () => resolve({ status: res.statusCode, headers: res.headers, chunks: chunks.length, body: Buffer.concat(chunks) }));
    });
    req.setTimeout(5_000, () => req.destroy(new Error('transport_test_timeout')));
    req.once('error', reject);
    req.end(body);
  });
}

test('gateway forwards streamed bytes and cookies, dispatches four workers, and never retries POST', { timeout: 20_000 }, async () => {
  const nonce = randomBytes(12).toString('hex');
  const directory = join(realpathSync(tmpdir()), `most-bim-device-${nonce}`);
  mkdirSync(directory, { mode: 0o700 });
  const servers = [];
  const workerPorts = [];
  const binary = Buffer.concat([Buffer.from([0, 255, 13, 10]), randomBytes(32_768)]);
  const cookies = ['alpha=one; Path=/; HttpOnly; SameSite=Lax', 'beta=two; Path=/; SameSite=Lax'];
  const postBodies = [];
  const pendingParallel = [];
  let gateway;
  try {
    for (let workerIndex = 0; workerIndex < 4; workerIndex++) {
      const server = createServer((req, res) => {
        if (req.url === '/__bim_acceptance/health') { res.end('{"status":"ready"}'); return; }
        if (req.url === '/api/v1/admin/bytes') {
          res.writeHead(206, { 'Set-Cookie': cookies, 'Content-Type': 'application/octet-stream', 'Content-Length': binary.length, 'X-Transport-Value': 'unchanged' });
          res.write(binary.subarray(0, 100));
          setTimeout(() => res.end(binary.subarray(100)), 40);
          return;
        }
        if (req.url === '/api/v1/admin/parallel') {
          pendingParallel.push({ res, workerIndex });
          if (pendingParallel.length === 4) for (const job of pendingParallel) job.res.end(String(job.workerIndex));
          return;
        }
        if (req.url === '/api/v1/admin/no-retry' && req.method === 'POST') {
          const chunks = [];
          req.on('data', (chunk) => chunks.push(chunk));
          req.once('end', () => { postBodies.push(Buffer.concat(chunks)); req.socket.destroy(); });
          return;
        }
        res.writeHead(404).end();
      });
      servers.push(server);
      workerPorts.push(await listen(server));
    }
    const ports = [...workerPorts];
    for (let index = 0; index < 4; index++) ports.push(await freePort(ports));
    const [httpPort, gatewayPort, reverbPort, redisPort] = ports.slice(4);
    const descriptorPath = join(directory, 'descriptor.json');
    writeFileSync(descriptorPath, JSON.stringify({
      runtime_directory: realpathSync.native(directory), expires_at: Math.floor(Date.now() / 1000) + 60,
      ui_origin: 'http://127.0.0.1:31391', admin_base_url: `http://127.0.0.1:${gatewayPort}`,
      http_port: httpPort, admin_http_port: gatewayPort, reverb_port: reverbPort, redis_port: redisPort, admin_worker_ports: workerPorts,
      environment: { APP_ENV: 'testing', DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55433',
        DB_DATABASE: `most_phpunit_${nonce}_testing`, DB_USERNAME: 'most_testing', DB_PASSWORD: 'most_testing_password',
        WEB_AUTH_ADMIN_ALLOWED_ORIGINS: 'http://127.0.0.1:31391' },
    }), { mode: 0o600 });
    gateway = spawn(process.execPath, [join(dirname(fileURLToPath(import.meta.url)), 'gateway.mjs')], {
      env: { ...process.env, BIM_DEVICE_ACCEPTANCE_DESCRIPTOR: descriptorPath }, stdio: 'ignore', windowsHide: true,
    });
    let ready = false;
    for (let attempt = 0; attempt < 40 && !ready; attempt++) {
      try { ready = (await fetch(gatewayPort, '/__bim_acceptance/health')).status === 200; } catch { await pause(50); }
    }
    assert.equal(ready, true, 'gateway did not become ready');
    const streamed = await fetch(gatewayPort, '/api/v1/admin/bytes');
    assert.equal(streamed.status, 206);
    assert.deepEqual(streamed.body, binary);
    assert.deepEqual(streamed.headers['set-cookie'], cookies);
    assert.equal(streamed.headers['x-transport-value'], 'unchanged');
    assert.ok(streamed.chunks >= 2);
    const parallel = await Promise.all(Array.from({ length: 4 }, () => fetch(gatewayPort, '/api/v1/admin/parallel')));
    assert.equal(new Set(parallel.map((response) => response.body.toString())).size, 4);
    assert.ok(parallel.every((response) => response.status === 200));
    const postBody = randomBytes(24);
    const failed = await fetch(gatewayPort, '/api/v1/admin/no-retry', 'POST', postBody);
    assert.equal(failed.status, 502);
    assert.equal(postBodies.length, 1);
    assert.deepEqual(postBodies[0], postBody);
  } finally {
    if (gateway && gateway.exitCode === null) {
      gateway.kill();
      await Promise.race([new Promise((resolve) => gateway.once('exit', resolve)), pause(2_000)]);
      if (gateway.exitCode === null) gateway.kill('SIGKILL');
    }
    for (const server of servers) { server.closeAllConnections(); await new Promise((resolve) => server.close(resolve)); }
    if (dirname(realpathSync(directory)) !== realpathSync(tmpdir()) || !/^most-bim-device-[a-f0-9]{24}$/.test(directory.split(/[\\/]/).at(-1))) throw new Error('transport_cleanup_path_invalid');
    rmSync(directory, { recursive: true });
  }
});
