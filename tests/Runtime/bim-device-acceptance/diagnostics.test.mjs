import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, realpathSync, rmSync, statSync } from 'node:fs';
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

test('configuration hook instruments a driver cached during real BootProviders and preserves channel auth', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  try {
    const result = runPhp(`${stageData(directory)}
      $context = \\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($data, 'POST', '/api/v1/admin/design-management/model-sessions/1/events');
      mkdir($data['runtime_directory'].'/config');
      file_put_contents($data['runtime_directory'].'/config/app.php', '<?php return ["env" => "testing"];');
      $GLOBALS['handler_calls'] = 0;
      $GLOBALS['channel_calls'] = 0;
      $GLOBALS['test_handler'] = \\GuzzleHttp\\HandlerStack::create(static function ($request, $options) {
        if ($options['timeout'] !== 30 || $options['connect_timeout'] !== 10) { throw new RuntimeException('default options changed'); }
        $GLOBALS['handler_calls']++;
        return new \\GuzzleHttp\\Promise\\FulfilledPromise(new \\GuzzleHttp\\Psr7\\Response(200, ['Content-Type' => 'application/json'], '{}'));
      });
      file_put_contents($data['runtime_directory'].'/config/broadcasting.php', <<<'CONFIG'
<?php return ['default' => 'reverb', 'connections' => ['reverb' => ['driver' => 'reverb', 'key' => 'unit-key',
'secret' => 'secret-canary', 'app_id' => 'unit-app', 'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
'client_options' => ['handler' => $GLOBALS['test_handler']]]]];
CONFIG);
      $app = new \\Illuminate\\Foundation\\Application($data['runtime_directory']);
      $app->useConfigPath($data['runtime_directory'].'/config');
      $app->instance('broadcast.manager', new \\Illuminate\\Broadcasting\\BroadcastManager($app));
      $app->register(new class($app) extends \\Illuminate\\Support\\ServiceProvider {
        public function boot(): void {
          $driver = $this->app['broadcast.manager']->connection('reverb');
          $driver->channel('design-model-session.{sessionId}', static function ($user, $sessionId) {
            $GLOBALS['channel_calls']++;
            return (int) $sessionId === 1 ? ['id' => (int) $user->getAuthIdentifier(), 'name' => 'Bootstrap user'] : false;
          });
          $this->app->instance('early_driver', $driver);
        }
      });
      \\Tests\\Runtime\\BimDeviceAcceptance\\registerReverbStages($app, $context);
      $app->bootstrapWith([\\Illuminate\\Foundation\\Bootstrap\\LoadConfiguration::class, \\Illuminate\\Foundation\\Bootstrap\\BootProviders::class]);
      $driver = $app['early_driver'];
      if ($app['broadcast.manager']->connection('reverb') !== $driver) { exit(90); }
      $request = \\Illuminate\\Http\\Request::create('/broadcasting/auth', 'POST', ['socket_id' => '100.1', 'channel_name' => 'presence-design-model-session.1']);
      $request->setUserResolver(static fn () => new \\Illuminate\\Auth\\GenericUser(['id' => 7]));
      $auth = $driver->auth($request);
      if (!is_string($auth['auth'] ?? null) || (json_decode($auth['channel_data'], true)['user_info']['name'] ?? null) !== 'Bootstrap user'
        || $GLOBALS['channel_calls'] !== 1) { exit(89); }
      $driver->broadcast(['presence-design-model-session.1'], 'diagnostic', ['payload' => 'secret-canary']);
      if ($GLOBALS['handler_calls'] !== 1) { exit(88); }`);
    assert.equal(result.status, 0, 'real bootstrap/cached driver/channel authentication failed');
    const text = readFileSync(join(directory, 'stages.jsonl'), 'utf8');
    assert.equal(text.includes('secret-canary'), false);
    const rows = text.trim().split('\n').map((line) => JSON.parse(line));
    assert.deepEqual(rows.map((row) => row.stage), ['application_start', 'broadcast_start', 'broadcast_end', 'shutdown']);
    assert.equal(rows[2].status_code, 200);
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

test('actual preflight failure archive survives private cleanup without leaking data', { timeout: 10_000 }, () => {
  const { directory, cleanup } = recordingDirectory();
  let proofPath;
  try {
    const result = runPhp(`${stageData(directory)}
      $context = \\Tests\\Runtime\\BimDeviceAcceptance\\beginStages($data, 'GET', '/api/v1/mobile/design-management/model-versions/1/offline-package');
      \\Tests\\Runtime\\BimDeviceAcceptance\\stage($context, 'kernel_start');
      file_put_contents($data['runtime_directory'].'/exceptions.jsonl', json_encode(['at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'class' => 'RuntimeException', 'message' => 'SQL secret-canary password', 'classifier' => 'maximum_execution_time',
        'limits' => ['limit_seconds' => 30, 'credential' => 'secret-canary'], 'args' => ['bearer-token']])."\\n");
      file_put_contents($data['runtime_directory'].'/http-timings.jsonl', json_encode(['completed_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'path' => '/api/v1/mobile/design-management/model-versions/1/offline-package', 'status' => 500, 'bootstrap_ms' => 3,
        'body' => 'SQL secret-canary', 'headers' => ['Authorization' => 'bearer-token']])."\\n");
      set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
      $test = new \\Tests\\Feature\\DesignManagement\\DesignBimDeviceAcceptanceHarnessTest('test_real_device_http_acceptance_until_stop_file');
      $original = null;
      try {
        try { (new ReflectionMethod($test, 'preflight'))->invoke($test, $data + ['version_id' => 1, 'base_url' => 'invalid-test-scheme://secret-canary']); }
        catch (Throwable $exception) {
          $original = $exception;
          $proof = \\Tests\\Runtime\\BimDeviceAcceptance\\archiveFailure($data, $exception);
          $foreign = $data; $foreign['environment']['APP_ENV'] = 'production';
          if (\\Tests\\Runtime\\BimDeviceAcceptance\\archiveFailure($foreign, $exception) !== null) { exit(93); }
          throw $exception;
        } finally {
          foreach (glob($data['runtime_directory'].'/*') as $privateFile) { unlink($privateFile); }
          rmdir($data['runtime_directory']);
        }
      } catch (Throwable $rethrown) { if ($rethrown !== $original) { exit(92); } }
      if (!is_string($proof ?? null) || !is_file($proof) || is_dir($data['runtime_directory'])) { exit(91); }
      echo json_encode(['proof_path' => $proof]);`);
    assert.equal(result.status, 0, 'actual preflight archive/rethrow/cleanup failed');
    proofPath = JSON.parse(result.stdout).proof_path;
    assert.equal(dirname(realpathSync.native(proofPath)), realpathSync.native(tmpdir()));
    assert.match(basename(proofPath), /^most-bim-device-failure-[a-f0-9]{24}-proof\.json$/);
    const text = readFileSync(proofPath, 'utf8');
    for (const canary of ['secret-canary', 'bearer-token', 'password', 'SQL', 'Authorization', 'invalid-test-scheme']) assert.equal(text.includes(canary), false);
    const proof = JSON.parse(text);
    assert.equal(proof.parent_failure.class, 'ErrorException');
    assert.ok(proof.parent_failure.own_frames.some((frame) => frame.file.endsWith('DesignBimDeviceAcceptanceHarnessTest.php')));
    assert.ok(proof.stages.some((row) => row.stage === 'kernel_start'));
    assert.equal(proof.exceptions[0].limits.limit_seconds, 30);
    assert.equal(proof.timings[0].status, 500);
    assert.ok(statSync(proofPath).size <= 1048576);
  } finally {
    if (proofPath && /^most-bim-device-failure-[a-f0-9]{24}-proof\.json$/.test(basename(proofPath))) rmSync(proofPath);
    if (existsSync(directory)) cleanup();
  }
});
