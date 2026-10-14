import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const helper = dirname(fileURLToPath(import.meta.url));
const phpString = (value) => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const project = `most-postgres-tests-${'a'.repeat(32)}`;

for (const [name, descriptorPort, runtimePort, runtimeProject, host, accepted] of [
  ['canonical', '55433', '', '', '127.0.0.1', true],
  ['dynamic', '41321', '41321', project, '127.0.0.1', true],
  ['mismatched port', '41322', '41321', project, '127.0.0.1', false],
  ['foreign project', '41321', '41321', 'most-production', '127.0.0.1', false],
  ['foreign host', '41321', '41321', project, '192.0.2.1', false],
]) {
  test(`PHP acceptance descriptor checks ${name} PostgreSQL endpoint without connecting`, () => {
    const temporary = realpathSync.native(tmpdir());
    const nonce = randomBytes(12).toString('hex');
    const directory = join(temporary, `most-bim-device-${nonce}`);
    mkdirSync(directory, { mode: 0o700 });
    try {
      const descriptorPath = join(directory, 'descriptor.json');
      writeFileSync(descriptorPath, JSON.stringify({
        runtime_directory: realpathSync.native(directory), expires_at: Math.floor(Date.now() / 1000) + 60,
        ui_origin: 'http://127.0.0.1:31391',
        environment: { APP_ENV: 'testing', DB_CONNECTION: 'pgsql', DB_HOST: host, DB_PORT: descriptorPort,
          DB_DATABASE: `most_phpunit_${nonce}_testing`, DB_USERNAME: 'most_testing', DB_PASSWORD: 'most_testing_password',
          WEB_AUTH_ADMIN_ALLOWED_ORIGINS: 'http://127.0.0.1:31391' },
      }), { mode: 0o600 });
      const script = `require ${phpString(join(helper, 'runtime.php'))}; echo \\Tests\\Runtime\\BimDeviceAcceptance\\descriptor()['environment']['DB_PORT'];`;
      const result = spawnSync(process.env.BIM_DEVICE_ACCEPTANCE_PHP ?? 'php', ['-r', script], {
        cwd: join(helper, '..', '..', '..'), encoding: 'utf8', timeout: 10_000, windowsHide: true,
        env: { ...process.env, BIM_DEVICE_ACCEPTANCE_DESCRIPTOR: descriptorPath, MOST_POSTGRES_TEST_PROFILE: '',
          MOST_POSTGRES_TEST_PORT: runtimePort, MOST_POSTGRES_TEST_PROJECT: runtimeProject },
      });
      if (accepted) {
        assert.equal(result.status, 0, result.stderr);
        assert.equal(result.stdout, descriptorPort);
      } else {
        assert.notEqual(result.status, 0);
        assert.match(result.stderr, /postgres_test_runtime_environment_unsafe|bim_acceptance_descriptor_unsafe_or_expired/);
      }
    } finally {
      if (dirname(realpathSync.native(directory)) !== temporary || !/^most-bim-device-[a-f0-9]{24}$/.test(basename(directory))) {
        throw new Error('postgres_descriptor_cleanup_path_invalid');
      }
      rmSync(directory, { recursive: true });
    }
  });
}
