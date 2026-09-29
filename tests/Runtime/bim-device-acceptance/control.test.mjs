import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createHmac, randomBytes, randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const helper = dirname(fileURLToPath(import.meta.url));
const phpString = (value) => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;

async function freePort(excluded) {
  for (let attempt = 0; attempt < 30; attempt += 1) {
    const server = createServer();
    await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
    const port = server.address().port;
    await new Promise((resolve) => server.close(resolve));
    if (!excluded.includes(port)) return port;
  }
  throw new Error('control_test_unique_port_unavailable');
}

function fetch(port, path, method = 'GET', body = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const bytes = body === null ? null : Buffer.from(JSON.stringify(body));
    const req = request({ hostname: '127.0.0.1', port, path, method, agent: false,
      headers: { ...headers, ...(bytes ? { 'Content-Type': 'application/json', 'Content-Length': bytes.length } : {}) } }, (response) => {
      const chunks = [];
      response.on('data', (chunk) => chunks.push(chunk));
      response.once('error', reject);
      response.once('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks) }));
    });
    req.setTimeout(8_000, () => req.destroy(new Error('control_test_http_timeout')));
    req.once('error', reject);
    req.end(bytes);
  });
}

async function stop(child) {
  if (child.exitCode !== null || child.signalCode !== null) return;
  const exited = new Promise((resolve) => child.once('exit', resolve));
  child.kill();
  await Promise.race([exited, pause(2_000)]);
  if (child.exitCode === null && child.signalCode === null) {
    child.kill('SIGKILL');
    await Promise.race([exited, pause(2_000)]);
  }
  assert.ok(child.exitCode !== null || child.signalCode !== null, 'owned control test PHP did not stop');
}

test('dedicated signed control remains responsive while the real native PHP worker is blocked', { timeout: 30_000 }, async (context) => {
  const nonce = randomBytes(12).toString('hex');
  const temporary = realpathSync.native(tmpdir());
  const directory = join(temporary, `most-bim-device-${nonce}`);
  mkdirSync(directory, { mode: 0o700 });
  const children = [];
  let nativeSlow;
  try {
    const phpProbe = spawnSync(process.env.BIM_DEVICE_ACCEPTANCE_PHP ?? 'php', ['-r', 'echo PHP_BINARY;'], { windowsHide: true, encoding: 'utf8', timeout: 5_000 });
    assert.equal(phpProbe.status, 0, 'PHP executable probe failed');
    const php = phpProbe.stdout.trim();
    const ports = [];
    for (let index = 0; index < 9; index += 1) ports.push(await freePort(ports));
    const [httpPort, adminPort, reverbPort, redisPort, controlPort, ...workerPorts] = ports;
    const sharedLegacyWorker = process.env.BIM_CONTROL_TEST_SHARED_WORKER === '1';
    const requestPort = sharedLegacyWorker ? httpPort : controlPort;
    const expires = Math.floor(Date.now() / 1000) + 60;
    const key = randomBytes(32).toString('hex');
    const controlPath = `/__bim_acceptance/control?signature=${createHmac('sha256', key).update(`control|${expires}`).digest('hex')}`;
    const controlBase = `http://127.0.0.1:${controlPort}`;
    const descriptorPath = join(directory, 'descriptor.json');
    const data = {
      schema_version: 1, runtime_directory: directory, expires_at: expires, file_signing_key: key,
      base_url: `http://127.0.0.1:${httpPort}`, admin_base_url: `http://127.0.0.1:${adminPort}`, control_base_url: controlBase,
      ui_origin: 'http://127.0.0.1:31391', http_port: httpPort, admin_http_port: adminPort,
      reverb_port: reverbPort, redis_port: redisPort, control_port: controlPort, admin_worker_ports: workerPorts,
      device_config: { BIM_API_CONTROL_BASE_URL: controlBase, BIM_API_CONTROL_URL: controlBase + controlPath },
      environment: { APP_ENV: 'testing', DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55433',
        DB_DATABASE: `most_phpunit_${nonce}_testing`, DB_USERNAME: 'most_testing', DB_PASSWORD: 'most_testing_password',
        WEB_AUTH_ADMIN_ALLOWED_ORIGINS: 'http://127.0.0.1:31391' },
    };
    if (sharedLegacyWorker) {
      delete data.control_port;
      delete data.control_base_url;
      delete data.device_config.BIM_API_CONTROL_BASE_URL;
      data.device_config.BIM_API_CONTROL_URL = data.base_url + controlPath;
    }
    writeFileSync(descriptorPath, JSON.stringify(data), { mode: 0o600 });
    const nativeRouter = join(directory, 'native-router.php');
    writeFileSync(nativeRouter, `<?php\nif (($_GET['slow'] ?? '') === '1') { file_put_contents(__DIR__.'/native-request.started', 'started'); usleep(4000000); }\nrequire ${phpString(join(helper, 'router.php'))};\n`, { mode: 0o600 });
    const workers = [[httpPort, nativeRouter], ...(sharedLegacyWorker ? [] : [[controlPort, join(helper, 'control.php')]])];
    for (const [port, router] of workers) {
      children.push(spawn(php, ['-S', `127.0.0.1:${port}`, router], {
        cwd: join(helper, '..', '..', '..'), env: { ...process.env, BIM_DEVICE_ACCEPTANCE_DESCRIPTOR: descriptorPath },
        stdio: 'ignore', windowsHide: true,
      }));
    }
    for (const [port] of workers) {
      let ready = false;
      for (let attempt = 0; attempt < 50 && !ready; attempt += 1) {
        try { ready = (await fetch(port, '/__bim_acceptance/health')).status === 200; } catch { await pause(50); }
      }
      assert.equal(ready, true, 'owned PHP worker did not become ready');
    }
    const apiProof = { actor: 'mobile', phase: 'apiComplete', issue_id: 1 };
    assert.equal((await fetch(requestPort, controlPath, 'POST', apiProof)).status, 200);
    const runId = randomUUID();
    const reset = await fetch(requestPort, controlPath, 'POST', { reset_pair: runId });
    assert.equal(reset.status, 200);
    const resetState = JSON.parse(reset.body);
    assert.equal(resetState.run_id, runId);
    assert.equal(resetState.phases.mobile.apiComplete.issue_id, 1);
    let nativeFinished = false;
    nativeSlow = fetch(httpPort, '/__bim_acceptance/health?slow=1').then((response) => { nativeFinished = true; return response; });
    nativeSlow.catch(() => {});
    for (let attempt = 0; attempt < 100 && !existsSync(join(directory, 'native-request.started')); attempt += 1) await pause(20);
    assert.equal(existsSync(join(directory, 'native-request.started')), true, 'native blocking request did not start');
    const started = Date.now();
    assert.equal((await fetch(requestPort, controlPath)).status, 200);
    const command = { id: 'leader-ready-control-test', type: 'leader_ready' };
    assert.equal((await fetch(requestPort, controlPath, 'POST', { commands: [command] })).status, 200);
    assert.equal((await fetch(requestPort, controlPath, 'POST', { actor: 'mobile', phase: `command_${command.id}`, type: command.type })).status, 200);
    const ack = JSON.parse((await fetch(requestPort, controlPath)).body);
    assert.equal(ack.run_id, runId);
    assert.equal(ack.commands[0].id, command.id);
    assert.equal(ack.phases.mobile[`command_${command.id}`].type, command.type);
    assert.equal(nativeFinished, false, 'control waited for the blocked native worker');
    const controlBatchMilliseconds = Date.now() - started;
    assert.ok(controlBatchMilliseconds < 2_000, 'control GET/POST/ACK exceeded its bounded local response window');
    context.diagnostic(`native_worker_block_ms=4000 control_get_post_ack_ms=${controlBatchMilliseconds}`);
    const nativeResponse = await nativeSlow;
    assert.equal(nativeResponse.status, 200);
    assert.equal(nativeResponse.body.toString(), '{"status":"ready"}');
    assert.equal((await fetch(controlPort, '/api/v1/mobile/design-management/model-sessions/1/participants')).status, 404);
    assert.equal((await fetch(controlPort, '/__bim_acceptance/device-config')).status, 404);
    assert.equal((await fetch(controlPort, '/__bim_acceptance/file')).status, 404);
    assert.equal((await fetch(controlPort, '/__bim_acceptance/control?signature=invalid')).status, 403);
    assert.equal((await fetch(controlPort, controlPath, 'GET', null, { Origin: 'http://127.0.0.1:31392' })).status, 403);
    assert.equal((await fetch(controlPort, controlPath, 'DELETE')).status, 405);
    assert.equal((await fetch(httpPort, controlPath)).status, 403);
    const cors = await fetch(controlPort, controlPath, 'OPTIONS', null, { Origin: data.ui_origin });
    assert.equal(cors.status, 204);
    assert.equal(cors.headers['access-control-allow-origin'], data.ui_origin);
    const sameReset = JSON.parse((await fetch(controlPort, controlPath, 'POST', { reset_pair: runId })).body);
    assert.equal(sameReset.phases.mobile[`command_${command.id}`].type, command.type);
    delete data.control_port;
    delete data.control_base_url;
    delete data.device_config.BIM_API_CONTROL_BASE_URL;
    data.device_config.BIM_API_CONTROL_URL = data.base_url + controlPath;
    writeFileSync(descriptorPath, JSON.stringify(data), { mode: 0o600 });
    assert.equal((await fetch(httpPort, controlPath)).status, 200, 'legacy native control fallback changed');
    assert.equal((await fetch(controlPort, controlPath)).status, 403);
    const exceptionProbe = spawnSync(php, ['-r', `require ${phpString(join(helper, 'runtime.php'))}; $data = json_decode(file_get_contents(${phpString(descriptorPath)}), true, 64, JSON_THROW_ON_ERROR); \\Tests\\Runtime\\BimDeviceAcceptance\\recordException($data, new RuntimeException('Error while reading line from the server. [tcp://secret-canary]')); \\Tests\\Runtime\\BimDeviceAcceptance\\recordException($data, new RuntimeException('SELECT secret-canary bearer-token password'));`], { windowsHide: true, encoding: 'utf8', timeout: 5_000 });
    assert.equal(exceptionProbe.status, 0, 'safe exception capture probe failed');
    const exceptionText = readFileSync(join(directory, 'exceptions.jsonl'), 'utf8');
    assert.equal(exceptionText.includes('secret-canary'), false);
    assert.equal(exceptionText.includes('bearer-token'), false);
    const exceptionRows = exceptionText.trim().split('\n').map((line) => JSON.parse(line));
    assert.equal(exceptionRows[0].class, 'RuntimeException');
    assert.equal(exceptionRows[0].message, 'Error while reading line from the server.');
    assert.equal(exceptionRows[1].message, null);
    assert.ok(exceptionRows.every((row) => Array.isArray(row.own_frames) && row.own_frames.every((frame) => !('args' in frame))));
  } finally {
    for (const child of children.reverse()) await stop(child);
    if (nativeSlow) await nativeSlow.catch(() => {});
    if (dirname(realpathSync.native(directory)) !== temporary || !/^most-bim-device-[a-f0-9]{24}$/.test(basename(directory))) throw new Error('control_test_cleanup_path_invalid');
    rmSync(directory, { recursive: true });
  }
});
