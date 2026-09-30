import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, normalize } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const helper = dirname(fileURLToPath(import.meta.url));
const project = join(helper, '..', '..', '..');
const phpString = (value) => `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;

test('acceptance bootstrap isolates manifests and keeps mobile and admin routes', { timeout: 60_000 }, () => {
  const temporary = realpathSync.native(tmpdir());
  const nonce = randomBytes(12).toString('hex');
  const directory = join(temporary, `most-bim-device-${nonce}`);
  mkdirSync(directory, { mode: 0o700 });
  for (const path of ['files', 'cache', 'storage/logs', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data']) {
    mkdirSync(join(directory, path), { recursive: true, mode: 0o700 });
  }
  try {
    const descriptor = join(directory, 'descriptor.json');
    const environment = {
      APP_ENV: 'testing', APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
      DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55433',
      DB_DATABASE: `most_phpunit_${nonce}_testing`, DB_USERNAME: 'most_testing',
      DB_PASSWORD: 'most_testing_password', WEB_AUTH_ADMIN_ALLOWED_ORIGINS: 'http://127.0.0.1:31391',
    };
    writeFileSync(descriptor, JSON.stringify({
      environment, runtime_directory: realpathSync.native(directory), redis_port: 1, organization_id: 1,
      expires_at: Math.floor(Date.now() / 1000) + 60,
      ui_origin: 'http://127.0.0.1:31391',
    }), { mode: 0o600 });
    const sharedServices = join(project, 'bootstrap', 'cache', 'services.php');
    const sharedPackages = join(project, 'bootstrap', 'cache', 'packages.php');
    const before = [sharedServices, sharedPackages].map((path) => existsSync(path) ? readFileSync(path) : null);
    const script = `require ${phpString(join(helper, 'runtime.php'))};
      $data = \\Tests\\Runtime\\BimDeviceAcceptance\\descriptor();
      $profile = [];
      $app = \\Tests\\Runtime\\BimDeviceAcceptance\\application($data, \\Illuminate\\Http\\Request::create('/api/v1/mobile/design-management/model-sessions/1/participants'), $profile);
      $routes = $app['router']->getRoutes();
      $mobile = $routes->match(\\Illuminate\\Http\\Request::create('/api/v1/mobile/design-management/model-sessions/1/participants'));
      $admin = $routes->match(\\Illuminate\\Http\\Request::create('/api/v1/admin/auth/login', 'POST'));
      echo json_encode([
        'mobile' => $mobile->uri(), 'admin' => $admin->uri(),
        'filament' => $app->getProvider(\\App\\Providers\\Filament\\AdminPanelProvider::class) !== null,
        'package_filament' => count(array_filter($app->make(\\Illuminate\\Foundation\\PackageManifest::class)->providers(),
          static fn ($provider) => str_starts_with($provider, 'Filament\\\\') || str_starts_with($provider, 'Livewire\\\\'))),
        'services_cache' => $app->getCachedServicesPath(),
      ], JSON_THROW_ON_ERROR);`;
    const result = spawnSync(process.env.BIM_DEVICE_ACCEPTANCE_PHP ?? 'php', ['-d', 'memory_limit=512M', '-r', script], {
      cwd: project, env: { ...process.env, BIM_DEVICE_ACCEPTANCE_DESCRIPTOR: descriptor },
      windowsHide: true, encoding: 'utf8', timeout: 55_000,
    });
    assert.equal(result.status, 0, result.stderr);
    const output = JSON.parse(result.stdout);
    assert.equal(output.mobile, 'api/v1/mobile/design-management/model-sessions/{sessionId}/participants');
    assert.equal(output.admin, 'api/v1/admin/auth/login');
    assert.equal(output.filament, false);
    assert.equal(output.package_filament, 0);
    assert.equal(normalize(output.services_cache), join(directory, 'services.php'));
    assert.equal(existsSync(join(directory, 'services.php')), true);
    assert.equal(existsSync(join(directory, 'packages.php')), true);
    [sharedServices, sharedPackages].forEach((path, index) => {
      assert.deepEqual(existsSync(path) ? readFileSync(path) : null, before[index]);
    });
  } finally {
    if (dirname(realpathSync.native(directory)) !== temporary || !/^most-bim-device-[a-f0-9]{24}$/.test(basename(directory))) {
      throw new Error('bootstrap_test_cleanup_path_invalid');
    }
    rmSync(directory, { recursive: true });
  }
});
