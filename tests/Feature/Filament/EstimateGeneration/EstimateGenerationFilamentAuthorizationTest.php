<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource\Pages\CreateEstimateGenerationTrainingDataset;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Helpers\PermissionTranslator;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class EstimateGenerationFilamentAuthorizationTest extends TestCase
{
    private const PERMISSIONS = [
        'estimate_generation.monitor',
        'estimate_generation.operate',
        'estimate_generation.datasets',
        'estimate_generation.benchmarks',
        'estimate_generation.settings',
        'estimate_generation.budgets',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'lang');
        $container->instance('translator', new Translator($loader, 'ru'));

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function accessMatrix(): iterable
    {
        $matrix = [
            'support_operator' => ['monitor', 'operate'],
            'qa_engineer' => ['monitor', 'datasets', 'benchmarks'],
            'security_auditor' => ['monitor'],
            'super_admin' => ['monitor', 'operate', 'datasets', 'benchmarks', 'settings', 'budgets'],
        ];

        foreach ($matrix as $role => $allowedActions) {
            foreach (self::PERMISSIONS as $permission) {
                $action = substr($permission, strlen('estimate_generation.'));

                yield "{$role}: {$action}" => [
                    $role,
                    $permission,
                    in_array($action, $allowedActions, true),
                ];
            }
        }
    }

    #[DataProvider('accessMatrix')]
    public function test_role_permissions_follow_least_privilege_matrix(
        string $role,
        string $permission,
        bool $expected,
    ): void {
        $definition = $this->roleDefinition($role);
        $grantedPermissions = array_merge(
            $definition['system_permissions'],
            ...array_values($definition['module_permissions']),
        );

        self::assertSame($expected, in_array('*', $grantedPermissions, true)
            || in_array($permission, $grantedPermissions, true));
    }

    public function test_filament_declares_exactly_six_ai_estimator_permissions(): void
    {
        $permissions = array_values(array_filter(
            (new ReflectionClass(FilamentPermission::class))->getConstants(),
            static fn (mixed $permission): bool => is_string($permission)
                && str_starts_with($permission, 'estimate_generation.'),
        ));

        self::assertSame(self::PERMISSIONS, $permissions);
    }

    public function test_ai_estimator_permissions_and_group_have_russian_labels(): void
    {
        $navigationTranslations = require dirname(__DIR__, 4).'/lang/ru/filament_navigation.php';
        self::assertSame('AI-сметчик', $navigationTranslations['groups']['ai_estimator']);

        foreach (self::PERMISSIONS as $permission) {
            $label = PermissionTranslator::getPermissionTranslation($permission, 'estimate_generation');

            self::assertNotSame($permission, $label);
            self::assertStringNotContainsString('permissions.', $label);
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $label);
        }
    }

    public function test_training_dataset_resource_uses_ai_estimator_navigation_contract(): void
    {
        self::assertSame(10, TrainingDatasetResource::getNavigationSort());

        $icon = new ReflectionProperty(TrainingDatasetResource::class, 'navigationIcon');
        self::assertSame('heroicon-o-academic-cap', $icon->getValue());

        $resourceSource = file_get_contents((new ReflectionClass(TrainingDatasetResource::class))->getFileName());
        $navigationSource = file_get_contents((new ReflectionClass(NavigationGroups::class))->getFileName());

        self::assertIsString($resourceSource);
        self::assertIsString($navigationSource);
        self::assertStringContainsString('return NavigationGroups::aiEstimator();', $resourceSource);
        self::assertStringContainsString('->label(self::aiEstimator())', $navigationSource);
        self::assertStringNotContainsString('->label(self::aiEstimator())->icon(', $navigationSource);
    }

    public function test_training_dataset_resource_uses_datasets_and_operate_permissions(): void
    {
        $source = file_get_contents((new ReflectionClass(TrainingDatasetResource::class))->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_DATASETS', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringNotContainsString('FilamentPermission::AI_ESTIMATOR_TRAINING_', $source);
    }

    public function test_auto_processing_requires_operate_permission(): void
    {
        $resourceSource = file_get_contents((new ReflectionClass(TrainingDatasetResource::class))->getFileName());
        $createPageSource = file_get_contents((new ReflectionClass(CreateEstimateGenerationTrainingDataset::class))->getFileName());

        self::assertIsString($resourceSource);
        self::assertIsString($createPageSource);
        self::assertStringContainsString('->visible(fn (): bool => self::canProcess())', $resourceSource);
        self::assertStringContainsString('TrainingDatasetResource::canProcess()', $createPageSource);
    }

    public function test_legacy_training_permissions_are_removed(): void
    {
        $permissionConstants = (new ReflectionClass(FilamentPermission::class))->getConstants();
        $permissionTranslations = require dirname(__DIR__, 4).'/lang/ru/permissions.php';

        foreach ($permissionConstants as $permission) {
            self::assertFalse(str_starts_with((string) $permission, 'system_admin.ai_estimator_training.'));
        }

        self::assertArrayNotHasKey('ai_estimator_training', $permissionTranslations['groups']);

        foreach (['qa_engineer', 'security_auditor'] as $role) {
            $definition = $this->roleDefinition($role);
            $encodedDefinition = json_encode($definition, JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString('ai_estimator_training', $encodedDefinition);
        }
    }

    /**
     * @return array{system_permissions: list<string>, module_permissions: array<string, list<string>>}
     */
    private function roleDefinition(string $role): array
    {
        $path = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR
            .'RoleDefinitions'.DIRECTORY_SEPARATOR.'system_admin'.DIRECTORY_SEPARATOR.$role.'.json';
        $contents = file_get_contents($path);

        self::assertIsString($contents);
        $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);
        self::assertIsArray($definition['system_permissions'] ?? null);
        self::assertIsArray($definition['module_permissions'] ?? null);

        return $definition;
    }
}
