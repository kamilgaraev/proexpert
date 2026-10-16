<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Providers\ModuleServiceProvider;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PackageCatalogScopeTest extends TestCase
{
    private Application $application;
    private Container $previousContainer;
    private string $directory;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->directory = sys_get_temp_dir().'/most-package-scope-'.bin2hex(random_bytes(8));
        $this->writeFixture($this->directory, 'initial');
        $this->application = $this->application($this->directory);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function testMetadataIsSharedOnlyUntilTheLifecycleIsReset(): void
    {
        $catalog = $this->application->make(PackageCatalogService::class);
        self::assertSame($catalog, $this->application->make(PackageCatalogService::class));
        self::assertSame('initial', $catalog->allPackages()[0]['slug']);
        self::assertSame(['initial'], array_keys($catalog->moduleDefinitions()));
        self::assertSame(['initial'], $catalog->foundationModules());

        $this->writeFixture($this->directory, 'next');
        self::assertSame('initial', $catalog->allPackages()[0]['slug']);
        self::assertSame(['initial'], array_keys($catalog->moduleDefinitions()));
        self::assertSame(['initial'], $catalog->foundationModules());

        $this->application->forgetScopedInstances();
        $next = $this->application->make(PackageCatalogService::class);
        self::assertNotSame($catalog, $next);
        self::assertSame('next', $next->allPackages()[0]['slug']);
        self::assertSame(['next'], array_keys($next->moduleDefinitions()));
        self::assertSame(['next'], $next->foundationModules());
    }

    public function testFreshEntitlementServicesShareOnlyTheMetadataCatalog(): void
    {
        $first = $this->application->make(OrganizationEntitlementService::class);
        $second = $this->application->make(OrganizationEntitlementService::class);
        $property = new ReflectionProperty(OrganizationEntitlementService::class, 'packageCatalog');

        self::assertNotSame($first, $second);
        self::assertSame($this->application->make(PackageCatalogService::class), $property->getValue($first));
        self::assertSame($property->getValue($first), $property->getValue($second));

        $this->application->forgetScopedInstances();
        self::assertNotSame($property->getValue($first), $property->getValue(
            $this->application->make(OrganizationEntitlementService::class),
        ));
    }

    public function testExplicitPathsDoNotReuseOrReplaceTheDefaultCatalog(): void
    {
        $default = $this->application->make(PackageCatalogService::class);
        $this->writeFixture($this->directory.'/custom-a', 'custom-a');
        $this->writeFixture($this->directory.'/custom-b', 'custom-b');
        $first = $this->application->makeWith(PackageCatalogService::class, $this->paths($this->directory.'/custom-a'));
        $second = $this->application->makeWith(PackageCatalogService::class, $this->paths($this->directory.'/custom-b'));

        self::assertNotSame($first, $second);
        self::assertNotSame($default, $first);
        self::assertSame('custom-a', $first->allPackages()[0]['slug']);
        self::assertSame('custom-b', $second->allPackages()[0]['slug']);
        self::assertSame($default, $this->application->make(PackageCatalogService::class));
        self::assertSame('initial', $default->allPackages()[0]['slug']);
    }

    public function testExistingInstanceOverrideIsPreserved(): void
    {
        $application = $this->application($this->directory, false);
        $override = new PackageCatalogService(...$this->paths($this->directory));
        $application->instance(PackageCatalogService::class, $override);
        (new ModuleServiceProvider($application))->register();

        self::assertSame($override, $application->make(PackageCatalogService::class));
    }

    public function testExistingTransientFactoryOverrideIsPreserved(): void
    {
        $application = $this->application($this->directory, false);
        $paths = $this->paths($this->directory);
        $application->bind(PackageCatalogService::class, static fn () => new PackageCatalogService(...$paths));
        (new ModuleServiceProvider($application))->register();

        self::assertNotSame($application->make(PackageCatalogService::class), $application->make(PackageCatalogService::class));
    }

    public function testLaterOverrideReplacesAnAlreadyResolvedCatalog(): void
    {
        $original = $this->application->make(PackageCatalogService::class);
        $override = new PackageCatalogService(...$this->paths($this->directory));
        $this->application->instance(PackageCatalogService::class, $override);

        self::assertNotSame($original, $this->application->make(PackageCatalogService::class));
        self::assertSame($override, $this->application->make(PackageCatalogService::class));
    }

    public function testIndependentApplicationsDoNotShareCatalogInstances(): void
    {
        $first = $this->application->make(PackageCatalogService::class);
        $other = $this->application($this->directory);

        self::assertNotSame($first, $other->make(PackageCatalogService::class));
    }

    private function application(string $basePath, bool $register = true): Application
    {
        $application = new Application($basePath);
        $application->instance('config', new Repository(['commercial_offers' => []]));
        if ($register) {
            (new ModuleServiceProvider($application))->register();
        }

        return $application;
    }

    private function paths(string $basePath): array
    {
        return [
            'packagesPath' => $basePath.'/config/Packages',
            'moduleListPath' => $basePath.'/config/ModuleList',
            'settingsPath' => $basePath.'/config/module_packages.php',
        ];
    }

    private function writeFixture(string $basePath, string $slug): void
    {
        (new Filesystem)->ensureDirectoryExists($basePath.'/config/Packages');
        (new Filesystem)->ensureDirectoryExists($basePath.'/config/ModuleList/core');
        file_put_contents($basePath.'/config/Packages/fixture.json', json_encode([
            'slug' => $slug,
            'tiers' => ['standard' => ['modules' => [$slug]]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($basePath.'/config/ModuleList/core/fixture.json', json_encode([
            'slug' => $slug,
            'auto_activate' => true,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($basePath.'/config/module_packages.php', '<?php return '.var_export([
            'foundation_modules' => [$slug],
        ], true).';');
    }
}
