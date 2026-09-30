<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Addons\FileManagement\FileManagementModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\DesignManagement\DesignManagementModule;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSet;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelOfflinePackageService;
use App\BusinessModules\Features\ExecutiveDocumentation\ExecutiveDocumentationModule;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Services\ReportTemplates\ReportTemplatesModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Modules\Core\AccessController;
use App\Services\Auth\JwtTokenIssuer;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Factory as JwtPayloadFactory;

final class DesignBimDeviceAcceptanceHarnessTest extends TestCase
{
    private const MAX_SECONDS = 5400;

    private const TOKEN_TTL_MINUTES = 100;

    private const UI_ORIGIN = 'http://127.0.0.1:31391';

    private const PREVIOUS_RUN_PORTS = [2014, 2016, 2018, 2019, 11097, 11103, 11107, 11108,
        29945, 29948, 29951, 29952, 34581, 34582, 34585, 34586];

    private const ADMIN_HTTP_WORKERS = 4;

    private const HELPERS = 'tests/Runtime/bim-device-acceptance';

    protected function setUp(): void
    {
        if (getenv('MOST_BIM_DEVICE_ACCEPTANCE') !== '1') {
            self::markTestSkipped('Opt-in only: MOST_BIM_DEVICE_ACCEPTANCE=1 and canonical single TestPath.');
        }
        $this->assertSingleTestSelection();
        parent::setUp();
        $connection = DB::connection();
        self::assertSame('testing', app()->environment());
        self::assertSame('pgsql', $connection->getDriverName());
        self::assertSame('127.0.0.1', $connection->getConfig('host'));
        self::assertSame('55433', (string) $connection->getConfig('port'));
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}_testing$/D', $connection->getDatabaseName());
        self::assertSame('most_testing', $connection->getConfig('username'));
        self::assertSame(1, $connection->transactionLevel());
        config(['web_auth.issuer' => 'https://bim-device-acceptance.invalid', 'web_auth.access_ttl_minutes' => self::TOKEN_TTL_MINUTES, 'jwt.ttl' => self::TOKEN_TTL_MINUTES]);
        app(JwtPayloadFactory::class)->setTTL(self::TOKEN_TTL_MINUTES);
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            return;
        }
        parent::tearDown();
    }

    public function test_real_device_http_acceptance_until_stop_file(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-bim-device-'.bin2hex(random_bytes(12));
        $filesystem = new Filesystem;
        foreach (['files', 'cache', 'storage/logs', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data'] as $path) {
            $filesystem->makeDirectory($directory.'/'.$path, 0700, true);
        }
        $children = [];
        $redisContainer = 'most-bim-device-redis-'.bin2hex(random_bytes(12));
        $redisStarted = false;
        $environment = $this->canonicalEnvironment();
        try {
            $httpPhpCommand = $this->httpPhpCommand();
            $fixture = $this->fixture($directory);
            $httpPort = $this->availablePort();
            $adminHttpPort = $this->availablePort();
            while ($adminHttpPort === $httpPort) {
                $adminHttpPort = $this->availablePort();
            }
            do {
                $controlPort = $this->availablePort();
            } while (in_array($controlPort, [$httpPort, $adminHttpPort], true));
            $reverbPort = $this->availablePort();
            while (in_array($reverbPort, [$httpPort, $adminHttpPort, $controlPort], true)) {
                $reverbPort = $this->availablePort();
            }
            $redisPort = $this->availablePort();
            while (in_array($redisPort, [$httpPort, $adminHttpPort, $controlPort, $reverbPort], true)) {
                $redisPort = $this->availablePort();
            }
            $adminWorkerPorts = [];
            for ($workerIndex = 0; $workerIndex < self::ADMIN_HTTP_WORKERS; $workerIndex++) {
                do {
                    $workerPort = $this->availablePort();
                } while (in_array($workerPort, [$httpPort, $adminHttpPort, $controlPort, $reverbPort, $redisPort, ...$adminWorkerPorts], true));
                $adminWorkerPorts[] = $workerPort;
            }
            $redis = new Process(['docker', 'run', '--rm', '--detach', '--name', $redisContainer,
                '--publish', '127.0.0.1:'.$redisPort.':6379', 'redis:7-alpine', 'redis-server', '--save', '', '--appendonly', 'no']);
            $redis->setTimeout(120);
            $redis->run();
            self::assertTrue($redis->isSuccessful(), 'Dedicated acceptance Redis startup failed.');
            $redisStarted = true;
            $base = 'http://127.0.0.1:'.$httpPort;
            $adminBase = 'http://127.0.0.1:'.$adminHttpPort;
            $controlBase = 'http://127.0.0.1:'.$controlPort;
            $environment = array_replace($environment, [
                'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'APP_URL' => $base, 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'WEB_AUTH_ISSUER' => 'https://bim-device-acceptance.invalid',
                'WEB_AUTH_ACCESS_TTL_MINUTES' => (string) self::TOKEN_TTL_MINUTES, 'JWT_TTL' => (string) self::TOKEN_TTL_MINUTES,
                'WEB_AUTH_ADMIN_ALLOWED_ORIGINS' => self::UI_ORIGIN,
                'APP_CONFIG_CACHE' => $directory.'/cache/config.php', 'APP_ROUTES_CACHE' => $directory.'/cache/routes.php',
                'APP_EVENTS_CACHE' => $directory.'/cache/events.php',
                'APP_DEBUG' => 'false', 'BROADCAST_CONNECTION' => 'reverb', 'CACHE_STORE' => 'redis',
                'REDIS_CLIENT' => 'predis', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => (string) $redisPort,
                'REDIS_URL' => '', 'REDIS_PASSWORD' => '', 'REDIS_USERNAME' => '', 'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1',
                'REDIS_PREFIX' => $redisContainer.':', 'CACHE_PREFIX' => $redisContainer.':',
                'REVERB_APP_ID' => 'bim-device-'.bin2hex(random_bytes(8)),
                'REVERB_APP_KEY' => 'bim-device-'.bin2hex(random_bytes(8)),
                'REVERB_APP_SECRET' => bin2hex(random_bytes(32)),
                'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => (string) $reverbPort, 'REVERB_SCHEME' => 'http',
                'REVERB_INTERNAL_HOST' => '127.0.0.1', 'REVERB_INTERNAL_PORT' => (string) $reverbPort,
                'REVERB_INTERNAL_SCHEME' => 'http', 'REVERB_SCALING_ENABLED' => 'false',
                'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'error',
            ]);
            $data = $fixture + [
                'schema_version' => 1, 'base_url' => $base, 'admin_base_url' => $adminBase, 'control_base_url' => $controlBase,
                'ui_origin' => self::UI_ORIGIN, 'environment' => $environment,
                'runtime_directory' => realpath($directory), 'expires_at' => time() + self::MAX_SECONDS,
                'file_signing_key' => bin2hex(random_bytes(32)), 'stop_file' => $directory.'/stop.json',
                'http_port' => $httpPort, 'admin_http_port' => $adminHttpPort, 'reverb_port' => $reverbPort,
                'control_port' => $controlPort,
                'redis_port' => $redisPort, 'admin_worker_ports' => $adminWorkerPorts,
            ];
            $data['device_config'] = [
                'BIM_API_BASE_URL' => $base.'/api/v1/mobile', 'BIM_API_ADMIN_BASE_URL' => $adminBase.'/api/v1/admin',
                'BIM_API_CONTROL_BASE_URL' => $controlBase,
                'BIM_API_TOKEN' => $data['mobile_token'], 'BIM_API_ADMIN_TOKEN' => $data['admin_token'],
                'BIM_API_USER_ID' => (string) $data['mobile_user_id'], 'BIM_API_ORGANIZATION_ID' => (string) $data['organization_id'],
                'BIM_API_PROJECT_ID' => (string) $data['project_id'], 'BIM_API_VERSION_ID' => (string) $data['version_id'],
                'BIM_API_SECOND_VERSION_ID' => (string) $data['second_version_id'],
                'BIM_API_SET_ID' => (string) $data['model_set_id'], 'BIM_API_SET_REVISION' => '1',
                'BIM_API_MODEL_SET_REVISION_ID' => (string) $data['model_set_revision_id'],
                'BIM_API_SESSION_ID' => (string) $data['session_id'], 'BIM_API_EXPRESS_ID' => '2863', 'BIM_API_WALL_EXPRESS_ID' => '12954',
                'BIM_API_SESSION_NEXT_ID' => (string) $data['second_session_id'],
                'BIM_API_ADMIN_USER_ID' => (string) $data['admin_user_id'], 'BIM_API_STOP_FILE' => $data['stop_file'],
            ];
            $data['device_config_url'] = $base.'/__bim_acceptance/device-config?signature='
                .hash_hmac('sha256', 'device-config|'.$data['expires_at'], $data['file_signing_key']);
            $data['device_config']['BIM_API_CONTROL_URL'] = $controlBase.'/__bim_acceptance/control?signature='
                .hash_hmac('sha256', 'control|'.$data['expires_at'], $data['file_signing_key']);
            $this->writeJson($directory.'/descriptor.json', $data);
            require_once base_path(self::HELPERS.'/runtime.php');
            \Tests\Runtime\BimDeviceAcceptance\configureStorage(app(), $data);
            $parentOffline = app(DesignModelOfflinePackageService::class)->offlinePackage(
                User::query()->findOrFail($data['mobile_user_id']), (int) $data['organization_id'], DesignArtifactVersion::query()->findOrFail($data['version_id'])
            );
            self::assertSame($data['geometry_sha256'], $parentOffline['geometry']['sha256']);
            $this->writeJson($directory.'/flutter-defines.json', [
                'API_BASE_URL' => $base.'/api/v1/mobile',
                'BIM_API_CONFIG_URL' => $data['device_config_url'],
            ]);
            DB::connection()->commit();
            DB::connection()->beginTransaction();
            $childEnvironment = $this->childEnvironment($environment, $directory.'/descriptor.json');
            $helper = base_path(self::HELPERS);
            $children[] = $reverb = new Process([PHP_BINARY, $helper.'/reverb.php'], base_path(), $childEnvironment);
            $children[] = $http = new Process([...$httpPhpCommand, '-S', '127.0.0.1:'.$httpPort, $helper.'/router.php'], base_path(), $childEnvironment);
            $children[] = $controlHttp = new Process([...$httpPhpCommand, '-S', '127.0.0.1:'.$controlPort, $helper.'/control.php'], base_path(), $childEnvironment);
            $adminWorkers = [];
            foreach ($adminWorkerPorts as $workerPort) {
                $children[] = $adminWorkers[] = new Process([...$httpPhpCommand, '-S', '127.0.0.1:'.$workerPort, $helper.'/router.php'], base_path(), $childEnvironment);
            }
            $nodeExecutable = (new ExecutableFinder)->find('node');
            if ($nodeExecutable === null) {
                throw new RuntimeException('bim_acceptance_node_executable_unavailable');
            }
            $children[] = $adminHttp = new Process([$nodeExecutable, $helper.'/gateway.mjs'], base_path(), $childEnvironment);
            foreach ($children as $child) {
                $child->setTimeout(self::MAX_SECONDS + 120);
                $child->disableOutput();
                $child->start();
            }
            $this->waitForServers($http, $adminHttp, $reverb, $data);
            self::assertTrue($controlHttp->isRunning(), 'Acceptance control HTTP startup failed.');
            $controlHealth = $this->request(array_replace($data, ['base_url' => $controlBase]), '/__bim_acceptance/health', '');
            self::assertSame('ready', $controlHealth['status'] ?? null);
            foreach ($adminWorkerPorts as $workerPort) {
                $health = $this->request(array_replace($data, ['base_url' => 'http://127.0.0.1:'.$workerPort]), '/__bim_acceptance/health', '');
                self::assertSame('ready', $health['status'] ?? null);
            }
            $this->preflight($data);
            $data['expires_at'] = time() + self::MAX_SECONDS;
            $data['device_config_url'] = $base.'/__bim_acceptance/device-config?signature='
                .hash_hmac('sha256', 'device-config|'.$data['expires_at'], $data['file_signing_key']);
            $data['device_config']['BIM_API_CONTROL_URL'] = $controlBase.'/__bim_acceptance/control?signature='
                .hash_hmac('sha256', 'control|'.$data['expires_at'], $data['file_signing_key']);
            $this->writeJson($directory.'/descriptor.json', $data);
            $this->writeJson($directory.'/flutter-defines.json', [
                'API_BASE_URL' => $base.'/api/v1/mobile', 'BIM_API_CONFIG_URL' => $data['device_config_url'],
            ]);
            fwrite(STDOUT, "\n".json_encode([
                'status' => 'ready', 'endpoint' => $base, 'admin_endpoint' => $adminBase, 'control_endpoint' => $controlBase,
                'control_port' => $controlPort, 'reverb_endpoint' => 'ws://127.0.0.1:'.$reverbPort,
                'descriptor' => $directory.'/descriptor.json', 'flutter_defines' => $directory.'/flutter-defines.json',
                'stop_file' => $data['stop_file'], 'expires_at' => $data['expires_at'],
                'organization_id' => $data['organization_id'], 'project_id' => $data['project_id'],
                'version_id' => $data['version_id'], 'session_id' => $data['session_id'], 'express_id' => 2863, 'redis_port' => $redisPort,
                'mobile_token_length' => strlen($data['mobile_token']), 'admin_token_length' => strlen($data['admin_token']),
                'http_opcache_enabled' => true,
                'admin_worker_ports' => $adminWorkerPorts,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            while (! is_file($data['stop_file'])) {
                self::assertLessThan($data['expires_at'], time(), 'Device acceptance timeout; no complete stop file.');
                self::assertTrue($http->isRunning(), 'Acceptance HTTP server exited.');
                self::assertTrue($adminHttp->isRunning(), 'Acceptance admin HTTP server exited.');
                self::assertTrue($controlHttp->isRunning(), 'Acceptance control HTTP server exited.');
                self::assertTrue($reverb->isRunning(), 'Acceptance Reverb server exited.');
                foreach ($adminWorkers as $adminWorker) {
                    self::assertTrue($adminWorker->isRunning(), 'Acceptance admin worker exited.');
                }
                usleep(250000);
            }
            $result = json_decode((string) file_get_contents($data['stop_file']), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('complete', $result['status'] ?? null, 'Device acceptance did not complete.');
            $this->finalChecks($data, $result);
            fwrite(STDOUT, json_encode(['status' => 'verified', 'issue_id' => $result['issue_id'],
                'project_id' => $data['project_id'], 'version_id' => $data['version_id'],
                'session_id' => $data['session_id'], 'express_id' => 2863,
            ], JSON_THROW_ON_ERROR)."\n");
        } catch (\Throwable $exception) {
            try {
                require_once base_path(self::HELPERS.'/runtime.php');
                $archive = \Tests\Runtime\BimDeviceAcceptance\archiveFailure($data ?? [
                    'schema_version' => 1, 'runtime_directory' => $directory, 'environment' => $environment,
                ], $exception);
                if ($archive !== null) {
                    fwrite(STDOUT, json_encode(['status' => 'failure_archive', 'path' => $archive], JSON_THROW_ON_ERROR)."\n");
                }
            } catch (\Throwable) {
            }
            throw $exception;
        } finally {
            $cleanupErrors = [];
            foreach (array_reverse($children) as $child) {
                try {
                    $child->stop(3);
                } catch (\Throwable) {
                    $cleanupErrors[] = 'child_process_stop_failed';
                }
            }
            try {
                if ($redisStarted) {
                    $stopRedis = new Process(['docker', 'stop', '--time', '3', $redisContainer]);
                    $stopRedis->setTimeout(15);
                    $stopRedis->run();
                    if (! $stopRedis->isSuccessful()) {
                        $cleanupErrors[] = 'dedicated_redis_stop_failed';
                    }
                }
            } catch (\Throwable) {
                $cleanupErrors[] = 'dedicated_redis_stop_failed';
            } finally {
                $root = realpath($directory);
                if ($root !== false && dirname($root) === realpath(sys_get_temp_dir())
                    && preg_match('/^most-bim-device-[a-f0-9]{24}$/D', basename($root)) === 1) {
                    $filesystem->deleteDirectory($root);
                }
            }
            self::assertSame([], $cleanupErrors, 'Acceptance cleanup incomplete.');
        }
    }

    private function httpPhpCommand(): array
    {
        $library = dirname(PHP_BINARY).'/ext/php_opcache.dll';
        self::assertFileExists($library, 'The opt-in Windows HTTP runtime requires its bundled OPcache extension.');
        $command = [PHP_BINARY, '-d', 'zend_extension='.$library, '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.memory_consumption=256', '-d', 'opcache.max_accelerated_files=32531',
            '-d', 'max_execution_time=120'];
        $probe = new Process([...$command, '-r',
            '$status = function_exists("opcache_get_status") ? opcache_get_status(false) : []; echo json_encode(["loaded" => extension_loaded("Zend OPcache"), "enabled" => $status["opcache_enabled"] ?? false, "max_execution_time" => (int) ini_get("max_execution_time")], JSON_THROW_ON_ERROR);']);
        $probe->setTimeout(10);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), 'Acceptance HTTP OPcache process probe failed.');
        $status = json_decode($probe->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['loaded' => true, 'enabled' => true, 'max_execution_time' => 120], $status,
            'Acceptance HTTP OPcache or execution limit is unavailable.');

        return $command;
    }

    private function assertSingleTestSelection(): void
    {
        $arguments = $_SERVER['argv'] ?? [];
        $selection = 'tests/Feature/DesignManagement/'.basename(__FILE__);
        self::assertContains($selection, $arguments, 'Pass only the canonical launcher -TestPath for this file.');
        self::assertNotContains('--testsuite', $arguments);
        self::assertNotContains('--filter', $arguments);
        foreach ($arguments as $argument) {
            self::assertFalse(str_starts_with((string) $argument, '--testsuite='));
            if (str_starts_with((string) $argument, 'tests/')) {
                self::assertSame($selection, $argument);
            }
        }
    }

    private function canonicalEnvironment(): array
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));
        if ($xml === false) {
            throw new RuntimeException('bim_acceptance_phpunit_configuration_invalid');
        }
        $result = [];
        foreach ($xml->php->env as $item) {
            $result[(string) $item['name']] = (string) $item['value'];
        }

        return $result;
    }

    private function childEnvironment(array $environment, string $descriptor): array
    {
        $result = array_fill_keys(array_keys(getenv()), false);
        foreach (['SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir', 'PATH', 'Path', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT'] as $key) {
            if (getenv($key) !== false) {
                $result[$key] = getenv($key);
            }
        }

        return array_replace($result, $environment, ['BIM_DEVICE_ACCEPTANCE_DESCRIPTOR' => $descriptor]);
    }

    private function availablePort(): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
            if ($socket === false) {
                throw new RuntimeException('bim_acceptance_port_unavailable');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
            if (! in_array($port, self::PREVIOUS_RUN_PORTS, true)) {
                return $port;
            }
        }

        throw new RuntimeException('bim_acceptance_retired_ports_reselected');
    }

    private function writeJson(string $path, array $data): void
    {
        self::assertNotFalse(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    private function fixture(string $directory): array
    {
        $password = 'Bim-Acceptance-'.bin2hex(random_bytes(16));
        $context = AdminApiTestContext::create(userAttributes: ['name' => 'Приёмка BIM: мобильный пользователь', 'has_completed_onboarding' => true, 'password' => Hash::make($password)], roleSlug: 'organization_owner');
        $adminPassword = 'Bim-Acceptance-'.bin2hex(random_bytes(16));
        $second = User::factory()->create(['name' => 'Приёмка BIM: административный пользователь', 'has_completed_onboarding' => true, 'password' => Hash::make($adminPassword), 'current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($second->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        UserRoleAssignment::assignRole($second, 'organization_owner', AuthorizationContext::getOrganizationContext((int) $context->organization->id));
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'is_archived' => false]);
        foreach ([$context->user, $second] as $user) {
            DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'role' => 'project_manager', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            UserRoleAssignment::assignRole($user, 'project_manager', AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id));
        }
        $this->entitlements($context);
        foreach ([$context->user, $second] as $user) {
            foreach (['design-management.models.view', 'design-management.models.edit', 'design-management.review'] as $permission) {
                self::assertTrue(app(AuthorizationService::class)->can($user, $permission, ['organization_id' => $context->organization->id, 'project_id' => $project->id, 'strict_project_scope' => true]));
            }
        }
        $scope = ['organization_id' => $context->organization->id, 'project_id' => $project->id];
        $author = ['created_by' => $context->user->id];
        $package = DesignPackage::query()->create($scope + $author + ['updated_by' => $context->user->id, 'title' => 'Приёмка BIM', 'status' => 'draft']);
        $artifact = DesignArtifact::query()->create($scope + $author + ['package_id' => $package->id, 'title' => 'Реальная IFC-модель', 'artifact_type' => 'model']);
        $source = base_path('tests/Fixtures/DesignManagement/thatopen-example.ifc');
        $identity = json_decode((string) file_get_contents(base_path(self::HELPERS.'/fragment-identity.json')), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($identity['source_sha256'], hash_file('sha256', $source));
        self::assertSame(2863, $identity['express_id']);
        self::assertSame(0, $identity['missing_sidecar_ids']);
        self::assertSame(0, $identity['source_global_id_mismatches']);
        $version = DesignArtifactVersion::query()->create($scope + $author + [
            'artifact_id' => $artifact->id, 'uploaded_by' => $context->user->id, 'title' => 'IFC версия 1', 'version_number' => '1',
            'source_file_path' => 'org-'.$context->organization->id.'/model.ifc', 'source_original_name' => 'model.ifc',
            'source_mime_type' => 'application/x-step', 'source_size_bytes' => filesize($source), 'file_format' => 'ifc', 'is_current' => true,
        ]);
        $generation = (string) Str::uuid();
        $prefix = 'org-'.$context->organization->id.'/pir/projects/'.$project->id.'/packages/'.$package->id.'/models/'.$version->id.'/viewer/'.$generation.'/';
        (new Filesystem)->makeDirectory($directory.'/files/'.$prefix, 0700, true);
        self::assertTrue(copy($source, $directory.'/files/org-'.$context->organization->id.'/model.ifc'));
        $offline = [];
        foreach (['geometry' => ['model.frag', 'application/octet-stream'], 'properties' => ['properties.ndjson', 'application/x-ndjson']] as $key => [$filename, $mime]) {
            $file = base_path(self::HELPERS.'/'.$filename);
            self::assertTrue(copy($file, $directory.'/files/'.$prefix.$filename));
            $offline[$key] = ['path' => $prefix.$filename, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'mime' => $mime];
            self::assertSame($identity[$key === 'geometry' ? 'geometry_sha256' : 'properties_sha256'], $offline[$key]['sha256']);
        }
        $metrics = json_decode((string) file_get_contents(base_path(self::HELPERS.'/fixture-metadata.json')), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame($identity['fragments_version'], $metrics['runtime']['fragments']);
        $derivative = DesignModelDerivative::query()->create($scope + $author + [
            'version_id' => $version->id, 'viewer_provider' => 'thatopen', 'derivative_format' => 'thatopen_frag', 'status' => 'ready',
            'derivative_file_path' => $prefix.'model.frag', 'progress_percent' => 100, 'processing_stage' => 'written', 'prepared_at' => now(),
            'metadata' => ['converter_version' => config('design_management.viewer_converter_version'), 'generation' => $generation,
                'runtime' => $metrics['runtime'], 'ifc_metadata' => $metrics['ifc_metadata'],
                'offline_package' => ['schema_version' => 1, 'generation' => $generation] + $offline],
        ]);
        $lines = file(base_path(self::HELPERS.'/properties.ndjson'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount($metrics['ifc_metadata']['indexed_element_count'], $lines);
        foreach ($lines as $line) {
            $element = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            DesignIfcModelElement::query()->create($scope + [
                'version_id' => $version->id, 'derivative_id' => $derivative->id,
                'express_id' => $element['express_id'], 'global_id' => $element['global_id'], 'category' => $element['category'],
                'name' => $element['name'], 'properties' => $element['properties'], 'classifications' => $element['classifications'],
            ]);
        }
        self::assertTrue(DesignIfcModelElement::query()->where('version_id', $version->id)->where('express_id', 2863)->exists());
        $artifact->update(['title' => 'Корпус А']);
        $secondArtifact = $artifact->replicate();
        $secondArtifact->title = 'Корпус Б';
        $secondArtifact->save();
        $secondVersion = $version->replicate();
        $secondVersion->artifact_id = $secondArtifact->id;
        $secondVersion->title = 'Корпус Б: IFC версия 1';
        $secondVersion->save();
        $secondGeneration = (string) Str::uuid();
        $secondPrefix = str_replace('/models/'.$version->id.'/viewer/'.$generation.'/', '/models/'.$secondVersion->id.'/viewer/'.$secondGeneration.'/', $prefix);
        (new Filesystem)->makeDirectory($directory.'/files/'.$secondPrefix, 0700, true);
        $secondOffline = [];
        foreach (['geometry' => 'model.frag', 'properties' => 'properties.ndjson'] as $key => $filename) {
            self::assertTrue(copy(base_path(self::HELPERS.'/'.$filename), $directory.'/files/'.$secondPrefix.$filename));
            $secondOffline[$key] = array_replace($offline[$key], ['path' => $secondPrefix.$filename]);
        }
        $secondDerivative = $derivative->replicate();
        $secondDerivative->version_id = $secondVersion->id;
        $secondDerivative->derivative_file_path = $secondPrefix.'model.frag';
        $secondDerivative->metadata = array_replace($derivative->metadata, ['generation' => $secondGeneration,
            'offline_package' => ['schema_version' => 1, 'generation' => $secondGeneration] + $secondOffline]);
        $secondDerivative->save();
        foreach (DesignIfcModelElement::query()->where('version_id', $version->id)->get() as $element) {
            $copy = $element->replicate();
            $copy->version_id = $secondVersion->id;
            $copy->derivative_id = $secondDerivative->id;
            $copy->save();
        }
        $set = DesignModelSet::query()->create($scope + $author + ['title' => 'Совместная приёмка BIM', 'revision' => 1]);
        $revision = DesignModelSetRevision::query()->create(['model_set_id' => $set->id, 'revision' => 1,
            'version_ids' => [$version->id, $secondVersion->id],
            'transforms' => [(string) $version->id => ['shift' => [0, 0, 0], 'rotation' => 0],
                (string) $secondVersion->id => ['shift' => [25, 0, 0], 'rotation' => 15]]]);
        $session = DesignModelSession::query()->create($scope + $author + ['model_set_id' => $set->id, 'model_set_revision_id' => $revision->id, 'title' => 'Мобильный и административный просмотр']);
        $secondSession = DesignModelSession::query()->create($scope + $author + ['model_set_id' => $set->id, 'model_set_revision_id' => $revision->id, 'title' => 'Проверка переключения сессии']);
        $uuid = (string) Str::uuid();
        UserAuthSession::query()->create(['user_id' => $second->id, 'organization_id' => $context->organization->id, 'session_uuid' => $uuid,
            'device_fingerprint' => hash('sha256', $uuid), 'device_name' => 'BIM acceptance admin', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        return $scope + ['package_id' => (int) $package->id, 'version_id' => (int) $version->id,
            'session_id' => (int) $session->id, 'model_set_id' => (int) $set->id, 'model_set_revision_id' => (int) $revision->id,
            'second_session_id' => (int) $secondSession->id,
            'second_version_id' => (int) $secondVersion->id, 'version_ids' => [(int) $version->id, (int) $secondVersion->id],
            'mobile_user_id' => (int) $context->user->id, 'admin_user_id' => (int) $second->id,
            'mobile_token' => app(JwtTokenIssuer::class)->issue($context->user, ['guard' => 'api_mobile', 'organization_id' => $context->organization->id]),
            'admin_token' => app(WebAuthTokenService::class)->issue($second, 'admin', $uuid, (int) $context->organization->id, false)->accessToken,
            'mobile_email' => $context->user->email, 'mobile_password' => $password,
            'admin_email' => $second->email, 'admin_password' => $adminPassword,
            'express_id' => 2863, 'wall_express_id' => 12954,
            'generation' => $generation, 'geometry_sha256' => $offline['geometry']['sha256'], 'properties_sha256' => $offline['properties']['sha256']];
    }

    private function entitlements(AdminApiTestContext $context): void
    {
        $modules = [
            [new DesignManagementModule, 'ModuleList/features/design-management.json'],
            [new ExecutiveDocumentationModule, 'ModuleList/features/executive-documentation.json'],
            [new ProjectManagementModule, 'ModuleList/features/project-management.json'],
            [new ContractManagementModule, 'ModuleList/features/contract-management.json'],
            [new FileManagementModule, 'ModuleList/addons/file-management.json'],
            [new ReportTemplatesModule, 'ModuleList/services/report-templates.json'],
        ];
        foreach ($modules as [$module, $configFile]) {
            $manifest = $module->getManifest();
            Module::query()->updateOrCreate(['slug' => $module->getSlug()], [
                'name' => $module->getName(), 'version' => $module->getVersion(), 'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value, 'category' => $manifest['category'] ?? 'construction',
                'description' => $module->getDescription(), 'features' => $module->getFeatures(), 'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(), 'conflicts' => $module->getConflicts(), 'limits' => $module->getLimits(),
                'class_name' => $module::class, 'config_file' => $configFile, 'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true, 'is_system_module' => false,
            ]);
        }
        $now = now();
        $account = OrganizationCommercialAccount::query()->create(['organization_id' => $context->organization->id, 'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1, 'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30)]);
        OrganizationPackageSubscription::query()->create(['organization_id' => $context->organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 39900,
            'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30)]);
        $access = app(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        self::assertTrue($access->hasModuleAccess((int) $context->organization->id, 'design-management'));
    }

    private function waitForServers(Process $http, Process $adminHttp, Process $reverb, array $data): void
    {
        $deadline = time() + 25;
        do {
            self::assertTrue($http->isRunning(), 'Acceptance HTTP startup failed.');
            self::assertTrue($adminHttp->isRunning(), 'Acceptance admin HTTP startup failed.');
            self::assertTrue($reverb->isRunning(), 'Acceptance Reverb startup failed.');
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$data['reverb_port'], $code, $message, 0.2);
            if ($socket !== false) {
                fclose($socket);
                $context = stream_context_create(['http' => ['timeout' => 0.5]]);
                if (@file_get_contents($data['base_url'].'/__bim_acceptance/health', false, $context) !== false
                    && @file_get_contents($data['admin_base_url'].'/__bim_acceptance/health', false, $context) !== false) {
                    return;
                }
            }
            usleep(100000);
        } while (time() < $deadline);
        self::fail('Acceptance server readiness timeout.');
    }

    private function request(array $data, string $path, string $actor, int $status = 200): array
    {
        $headers = ['Accept: application/json'];
        if ($actor !== '') {
            $headers[] = 'Authorization: Bearer '.$data[$actor.'_token'];
        }
        if ($actor === 'admin') {
            $headers[] = 'Origin: '.$data['ui_origin'];
        }
        $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 45, 'ignore_errors' => true]]);
        $base = $actor === 'admin' ? $data['admin_base_url'] : $data['base_url'];
        $body = file_get_contents($base.$path, false, $context);
        self::assertIsString($body, 'Acceptance HTTP response unavailable.');
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $safeMessage = is_string($decoded['message'] ?? null) ? mb_substr($decoded['message'], 0, 250) : '';
        self::assertMatchesRegularExpression('#^HTTP/\S+ '.$status.'\b#', $http_response_header[0] ?? '', 'Acceptance HTTP status mismatch: '.$path.'; '.$safeMessage);

        return $decoded;
    }

    private function preflight(array $data): void
    {
        $path = '/api/v1/mobile/design-management/model-versions/'.$data['version_id'].'/offline-package';
        $this->request($data, $path, '', 401);
        $manifest = $this->request($data, $path, 'mobile')['data'];
        self::assertSame($data['generation'], $manifest['generation']);
        foreach (['geometry', 'properties'] as $type) {
            self::assertSame($data[$type.'_sha256'], $manifest[$type]['sha256']);
            $content = file_get_contents($manifest[$type]['url'], false, stream_context_create(['http' => ['timeout' => 45]]));
            self::assertIsString($content);
            self::assertSame($manifest[$type]['sha256'], hash('sha256', $content));
            self::assertSame($manifest[$type]['size'], strlen($content));
        }
        foreach (['mobile', 'admin'] as $actor) {
            $session = $this->request($data, '/api/v1/'.$actor.'/design-management/model-sessions/'.$data['session_id'].'/bootstrap', $actor)['data'];
            self::assertSame($data['model_set_revision_id'], $session['model_set_revision_id']);
            self::assertSame($data['version_ids'], $session['models']);
            self::assertTrue($session['realtime']['enabled']);
        }
    }

    private function finalChecks(array $data, array $result): void
    {
        $data['admin_token'] = $this->freshAdminToken($data);
        self::assertIsInt($result['issue_id'] ?? null);
        $issue = QualityDefect::query()->where('organization_id', $data['organization_id'])->where('project_id', $data['project_id'])->findOrFail($result['issue_id']);
        self::assertSame($data['mobile_user_id'], (int) $issue->created_by);
        self::assertSame(1, QualityDefect::query()->where('organization_id', $data['organization_id'])->where('project_id', $data['project_id'])
            ->where('created_by', $data['mobile_user_id'])->count());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{16,128}$/D', $result['client_operation_id'] ?? '');
        $creations = DB::table('mobile_mutation_idempotencies')->where('organization_id', $data['organization_id'])
            ->where('user_id', $data['mobile_user_id'])->where('operation', 'design-management.issue.create')->get();
        self::assertCount(1, $creations);
        self::assertSame($result['client_operation_id'], $creations->first()->idempotency_key);
        self::assertSame((int) $issue->id, (int) $creations->first()->resource_id);
        self::assertGreaterThanOrEqual(3, (int) $issue->getAttribute('row_version'));
        $context = $issue->metadata['design_issue_context'];
        self::assertSame($data['version_id'], (int) $context['version_id']);
        self::assertSame('2863', (string) $context['bim_element_id']);
        self::assertNotEmpty($context['camera'] ?? $context['view_state'] ?? null);
        self::assertCount(1, $issue->photos);
        $photo = $issue->photos->first();
        self::assertNotNull($photo);
        foreach (['snapshot' => $context['snapshot']['path'], 'photo' => $photo->url] as $type => $key) {
            self::assertStringStartsWith('org-'.$data['organization_id'].'/', $key);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result[$type.'_sha256'] ?? '');
            $file = $data['runtime_directory'].'/files/'.$key;
            self::assertFileExists($file);
            self::assertSame($result[$type.'_sha256'], hash_file('sha256', $file));
            $image = getimagesize($file);
            self::assertIsArray($image);
            self::assertGreaterThan(1, $image[0]);
            self::assertGreaterThan(1, $image[1]);
            if ($type === 'snapshot') {
                self::assertSame('image/png', $image['mime']);
            }
        }
        self::assertTrue((bool) $photo->storage_identity_verified);
        self::assertSame($result['photo_sha256'], $photo->storage_sha256);
        $mobile = $this->request($data, '/api/v1/mobile/design-management/project-issues/'.$issue->id, 'mobile')['data'];
        $admin = $this->request($data, '/api/v1/admin/design-management/issues/'.$issue->id, 'admin')['data'];
        self::assertSame($mobile['id'], $admin['id']);
        self::assertSame($mobile['revision'], $admin['revision']);
        self::assertSame('2863', (string) $admin['context']['bim_element_id']);
        $this->sessionCompletionChecks($data, $result);
    }

    private function sessionCompletionChecks(array $data, array $result): void
    {
        $file = $data['runtime_directory'].'/control.json';
        self::assertFileExists($file);
        self::assertLessThanOrEqual(262144, filesize($file));
        $control = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        $api = $control['phases']['mobile']['apiComplete'] ?? null;
        self::assertIsArray($api, 'Actual device API acceptance proof absent.');
        self::assertSame('mobile', $api['actor'] ?? null);
        self::assertSame('apiComplete', $api['phase'] ?? null);
        self::assertSame($result['issue_id'], $api['issue_id'] ?? null);
        foreach (['client_operation_id', 'snapshot_sha256', 'photo_sha256'] as $key) {
            self::assertSame($result[$key], $api[$key] ?? null);
        }
        foreach (['actual_api_sync_and_admin_visibility', 'lost_create_ack_idempotency_no_duplicate', 'attachment_bytes_verified'] as $check) {
            self::assertTrue($api['checks'][$check] ?? false, 'Actual device API assertion missing: '.$check);
        }
        $checks = [
            'mobile' => ['native_auth_bridge', 'jwt_not_in_js', 'complete_full_follow', 'own_unfollow_preserved', 'model_version_selection_deduplicated',
                'late_join_snapshot', 'reconnect_and_session_change', 'left_sessions_cleaned'],
            'admin' => ['real_auth', 'real_workspace', 'echo_presence', 'named_cursor', 'selection_during_camera_burst',
                'full_follow', 'own_unfollow_preserved', 'model_version_selection_deduplicated', 'late_join_snapshot', 'reconnect', 'next_session_cleanup',
                'participants_absent', 'view_states_absent'],
        ];
        foreach (['mobile', 'admin'] as $actor) {
            $client = $result[$actor.'_client_id'] ?? '';
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,100}$/D', $client);
            $proof = $control['phases'][$actor]['complete'] ?? null;
            self::assertIsArray($proof, 'Actual session acceptance proof absent: '.$actor);
            self::assertSame($actor, $proof['actor'] ?? null);
            self::assertSame('complete', $proof['phase'] ?? null);
            self::assertSame($client, $proof['client_id'] ?? null);
            self::assertSame($data['session_id'], $proof['session_id'] ?? null);
            self::assertSame($data['second_session_id'], $proof['second_session_id'] ?? null);
            self::assertSame($data['model_set_revision_id'], $proof['model_set_revision_id'] ?? null);
            self::assertSame($data['version_ids'], $proof['version_ids'] ?? null);
            self::assertSame([$data['session_id'], $data['second_session_id']], $proof['left_session_ids'] ?? null);
            self::assertIsInt($proof['at'] ?? null);
            self::assertGreaterThanOrEqual($data['expires_at'] - self::MAX_SECONDS, $proof['at']);
            self::assertLessThanOrEqual(time(), $proof['at']);
            foreach ($checks[$actor] as $check) {
                self::assertTrue($proof['checks'][$check] ?? false, 'Actual session assertion missing: '.$actor.'.'.$check);
            }
            foreach (['camera', 'cursor', 'select', 'view'] as $type) {
                self::assertContains($type, $proof['received_types'] ?? [], 'Actual incoming realtime evidence missing.');
            }
            $reader = $actor === 'mobile' ? 'admin' : 'mobile';
            foreach ([$data['session_id'], $data['second_session_id']] as $sessionId) {
                $prefix = '/api/v1/'.$reader.'/design-management/model-sessions/'.$sessionId;
                $participants = $this->request($data, $prefix.'/participants', $reader)['data'];
                self::assertIsArray($participants);
                self::assertNotContains($client, array_column($participants, 'client_id'), 'Departed session client persists.');
                self::assertNull($this->request($data, $prefix.'/view-state/'.$client, $reader)['data'], 'Departed view state persists.');
            }
        }
        self::assertNotSame($result['mobile_client_id'], $result['admin_client_id']);
        $report = $control['phases']['admin']['complete']['report'] ?? null;
        self::assertIsArray($report);
        self::assertNotEmpty($report['run_id'] ?? null);
        self::assertIsArray($report['phase_names'] ?? null);
        foreach (['admin_workspace_ready', 'joined', 'named_cursor_and_short_events', 'full_follow_actual_workspace',
            'selection_during_camera_burst', 'late_join_snapshot', 'reconnect_and_cleanup', 'next_session_cleanup'] as $phase) {
            self::assertContains($phase, $report['phase_names']);
        }
        self::assertIsInt($report['evidence_count'] ?? null);
        self::assertGreaterThanOrEqual(8, $report['evidence_count']);
    }

    private function freshAdminToken(array $data): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nContent-Type: application/json\r\nOrigin: ".$data['ui_origin'],
            'content' => json_encode(['email' => $data['admin_email'], 'password' => $data['admin_password'], 'remember_me' => false], JSON_THROW_ON_ERROR),
        ]]);
        $body = file_get_contents($data['admin_base_url'].'/api/v1/admin/auth/login', false, $context);
        self::assertIsString($body, 'Acceptance admin login unavailable.');
        self::assertMatchesRegularExpression('#^HTTP/\S+ 200\b#', $http_response_header[0] ?? '', 'Acceptance admin login failed.');
        $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($data['admin_user_id'], (int) ($response['data']['user']['id'] ?? 0));
        $token = $response['data']['token'] ?? null;
        self::assertIsString($token, 'Acceptance admin access token absent.');
        self::assertNotSame('', $token);

        return $token;
    }
}
