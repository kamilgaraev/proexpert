import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync, realpathSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const helper = dirname(fileURLToPath(import.meta.url));
const phpString = (value) => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const php = process.env.BIM_DEVICE_ACCEPTANCE_PHP ?? 'php';

function recordingDirectory() {
  const temporary = realpathSync.native(tmpdir());
  const directory = join(temporary, `most-bim-device-${randomBytes(12).toString('hex')}`);
  mkdirSync(directory, { mode: 0o700 });
  return { directory, cleanup() {
    if (dirname(realpathSync.native(directory)) !== temporary || !/^most-bim-device-[a-f0-9]{24}$/.test(basename(directory))) {
      throw new Error('diagnostics_test_cleanup_path_invalid');
    }
    rmSync(directory, { recursive: true });
  } };
}

function runPhp(body) {
  return spawnSync(php, ['-r', `require ${phpString(join(helper, 'runtime.php'))}; ${body}`],
    { windowsHide: true, encoding: 'utf8', timeout: 5_000 });
}

function stageData(directory) {
  return `$data = ['schema_version' => 1, 'runtime_directory' => ${phpString(directory)}, 'expires_at' => time() + 60,
    'environment' => ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
      'DB_PORT' => '55433', 'DB_DATABASE' => 'most_phpunit_0123456789abcdef01234567_testing']];`;
}

test('safe PHP failure classifiers preserve numeric limits without raw messages', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  try {
    const messages = [
      'Maximum execution time of 30 seconds exceeded SQL secret-canary bearer-token password',
      'Allowed memory size of 134217728 bytes exhausted (tried to allocate 65536 bytes) secret-canary',
      'SELECT secret-canary bearer-token password',
      'SELECT Maximum execution time of 30 seconds exceeded secret-canary',
      'Maximum execution time of 30 bytes exceeded secret-canary',
      'Error while reading line from the server. [tcp://secret-canary]',
    ];
    const result = runPhp(`$data = ['runtime_directory' => ${phpString(directory)}]; foreach ([${messages.map(phpString).join(', ')}] as $message) { \\Tests\\Runtime\\BimDeviceAcceptance\\recordException($data, new RuntimeException($message)); }`);
    assert.equal(result.status, 0, 'exception classifier child failed');
    const text = readFileSync(join(directory, 'exceptions.jsonl'), 'utf8');
    for (const canary of ['secret-canary', 'bearer-token', 'password', 'SELECT', 'SQL']) {
      assert.equal(text.includes(canary), false, 'private error text escaped classifier');
    }
    const rows = text.trim().split('\n').map((line) => JSON.parse(line));
    assert.deepEqual(rows.map((row) => [row.classifier, row.limits]), [
      ['maximum_execution_time', { limit_seconds: 30 }],
      ['memory_exhausted', { limit_bytes: 134217728, allocation_bytes: 65536 }],
      [null, []], [null, []], [null, []], [null, []],
    ]);
    assert.equal(rows[5].message, 'Error while reading line from the server.');
    assert.ok(rows.every((row) => Array.isArray(row.own_frames) && row.own_frames.every((frame) => !('args' in frame))));
  } finally { cleanup(); }
});

test('immediate stages persist before fatal and shutdown excludes private error text', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  try {
    const result = runPhp(`${stageData(directory)}
      $context = \\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($data, 'POST', '/api/v1/admin/design-management/model-sessions/1/events?token=secret-canary');
      \\Tests\\Runtime\\BimDeviceAcceptance\\stage($context, 'bootstrap_done', ['payload' => 'secret-canary']);
      \\Tests\\Runtime\\BimDeviceAcceptance\\stage($context, 'kernel_start', ['headers' => 'bearer-token']);
      if (!str_contains(file_get_contents($data['runtime_directory'].'/stages.jsonl'), '"stage":"kernel_start"')) { exit(99); }
      trigger_error('secret-canary SQL password bearer-token', E_USER_ERROR);`);
    assert.equal(result.status, 255, 'actual fatal child did not reach fatal after immediate marker');
    const text = readFileSync(join(directory, 'stages.jsonl'), 'utf8');
    for (const canary of ['secret-canary', 'bearer-token', 'password', 'SQL', 'token=']) assert.equal(text.includes(canary), false);
    const rows = text.trim().split('\n').map((line) => JSON.parse(line));
    assert.deepEqual(rows.map((row) => row.stage), ['application_start', 'bootstrap_done', 'kernel_start', 'shutdown']);
    assert.equal(rows.at(-1).last_marker, 'kernel_start');
    assert.equal(rows.at(-1).fatal_type, 256);
    assert.equal(rows.at(-1).fatal_file, null);
    assert.ok(rows.at(-1).fatal_line > 0);
    assert.ok(rows.every((row) => row.pid > 0 && Number.isInteger(row.max_execution_time) && row.elapsed_ms >= 0));
    assert.ok(rows.every((row, index) => index === 0 || row.elapsed_ms >= rows[index - 1].elapsed_ms));
  } finally { cleanup(); }
});

test('broadcast markers delegate original handlers options responses and failures', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  try {
    const result = runPhp(`require ${phpString(join(helper, '../../../vendor/autoload.php'))}; ${stageData(directory)}
      $context = \\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($data, 'POST', '/api/v1/admin/design-management/model-sessions/1/events');
      $response = new \\GuzzleHttp\\Psr7\\Response(202, ['X-Private' => 'secret-canary'], 'private-payload');
      $called = 0;
      $stack = \\GuzzleHttp\\HandlerStack::create(static function ($request, $options) use ($response, &$called) {
        if ($request->getHeaderLine('Authorization') !== 'Bearer secret-canary' || (string) $request->getBody() !== 'SQL private-payload'
          || $options['timeout'] !== 30 || $options['connect_timeout'] !== 10) { throw new RuntimeException('handler delegation changed'); }
        $called++;
        return new \\GuzzleHttp\\Promise\\FulfilledPromise($response);
      });
      $originalStack = (string) $stack;
      $options = ['handler' => $stack, 'timeout' => 30, 'connect_timeout' => 10, 'verify' => false];
      $instrumented = \\Tests\\Runtime\\BimDeviceAcceptance\\reverbClientOptions($options, $context);
      if ((string) $stack !== $originalStack || $instrumented['verify'] !== false) { throw new RuntimeException('original options changed'); }
      $client = new \\GuzzleHttp\\Client($instrumented);
      $actual = $client->request('POST', 'http://127.0.0.1/events?token=secret-canary', ['headers' => ['Authorization' => 'Bearer secret-canary'], 'body' => 'SQL private-payload']);
      if ($actual !== $response || $called !== 1) { throw new RuntimeException('response or call count changed'); }
      $failure = new RuntimeException('secret-canary SQL password');
      $failed = \\Tests\\Runtime\\BimDeviceAcceptance\\reverbClientOptions(['handler' => static fn () => new \\GuzzleHttp\\Promise\\RejectedPromise($failure)], $context);
      try { (new \\GuzzleHttp\\Client($failed))->request('POST', 'http://127.0.0.1/events'); exit(98); }
      catch (RuntimeException $actualFailure) { if ($actualFailure !== $failure) { exit(97); } }
      $noContext = null;
      if (\\Tests\\Runtime\\BimDeviceAcceptance\\reverbClientOptions($options, $noContext) !== $options) { exit(96); }
      $defaults = \\Tests\\Runtime\\BimDeviceAcceptance\\reverbClientOptions([], $context);
      if (array_key_exists('timeout', $defaults) || array_key_exists('connect_timeout', $defaults)) { exit(95); }`);
    assert.equal(result.status, 0, 'transparent broadcast marker child failed');
    const text = readFileSync(join(directory, 'stages.jsonl'), 'utf8');
    for (const canary of ['secret-canary', 'bearer-token', 'password', 'SQL', 'token=', 'private-payload', 'Authorization']) assert.equal(text.includes(canary), false);
    const rows = text.trim().split('\n').map((line) => JSON.parse(line));
    assert.deepEqual(rows.map((row) => row.stage), ['application_start', 'broadcast_start', 'broadcast_end', 'broadcast_start', 'broadcast_failure', 'shutdown']);
    assert.equal(rows[2].status_code, 202);
    assert.equal(rows[4].exception_class, 'RuntimeException');
  } finally { cleanup(); }
});

test('stage recorder rejects foreign runtime and never exceeds its private file budget', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  try {
    const result = runPhp(`${stageData(directory)}
      $foreign = $data; $foreign['environment']['APP_ENV'] = 'production';
      if (\\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($foreign, 'POST', '/api/v1/admin/design-management') !== null) { exit(94); }
      $context = \\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($data, 'POST', '/api/v1/admin/design-management');
      file_put_contents($data['runtime_directory'].'/stages.jsonl', str_repeat('x', 1048576));
      \\Tests\\Runtime\\BimDeviceAcceptance\\stage($context, 'kernel_end', ['payload' => 'secret-canary']);`);
    assert.equal(result.status, 0, 'bounded private stage guard child failed');
    assert.equal(statSync(join(directory, 'stages.jsonl')).size, 1048576);
  } finally { cleanup(); }
});
