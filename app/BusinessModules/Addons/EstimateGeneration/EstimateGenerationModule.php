<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration;

use App\Enums\BillingModel;
use App\Enums\ModuleType;
use App\Models\OrganizationModuleActivation;
use App\Modules\Contracts\ConfigurableInterface;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use InvalidArgumentException;

final class EstimateGenerationModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return (string) ($this->getManifest()['name'] ?? 'AI Генерация Смет');
    }

    public function getSlug(): string
    {
        return 'ai-estimates';
    }

    public function getVersion(): string
    {
        return (string) ($this->getManifest()['version'] ?? '1.0.0');
    }

    public function getDescription(): string
    {
        return (string) ($this->getManifest()['description'] ?? 'AI-сметчик по проектной документации, чертежам и нормативной базе');
    }

    public function getType(): ModuleType
    {
        return ModuleType::ADDON;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        $manifest = json_decode((string) file_get_contents(config_path('ModuleList/addons/ai-estimates.json')), true);

        return is_array($manifest) ? $manifest : [];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function upgrade(string $fromVersion): void
    {
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(AccessController::class);

        return $accessController->hasModuleAccess($organizationId, 'organizations')
            && $accessController->hasModuleAccess($organizationId, 'users')
            && $accessController->hasModuleAccess($organizationId, 'budget-estimates');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'budget-estimates'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $permission): ?string => isset($permission['name']) ? (string) $permission['name'] : null,
            $this->getManifest()['permissions'] ?? []
        )));
    }

    public function getFeatures(): array
    {
        return array_values(array_map('strval', $this->getManifest()['features'] ?? []));
    }

    public function getLimits(): array
    {
        return $this->getManifest()['limits'] ?? [];
    }

    public function getDefaultSettings(): array
    {
        return [
            'estimator' => [
                'require_normative_price' => true,
                'require_source_traceability' => true,
                'allow_review_only_inferences' => true,
            ],
            'ocr' => [
                'enabled' => true,
                'max_file_size_mb' => 200,
                'allowed_file_types' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'dwg', 'dxf'],
            ],
            'generation' => [
                'max_files_per_session' => 25,
                'max_parallel_document_jobs' => 4,
                'max_parallel_generation_jobs_per_organization' => 2,
            ],
        ];
    }

    public function validateSettings(array $settings): bool
    {
        $limits = [
            'ocr.max_file_size_mb' => [1, 500],
            'generation.max_files_per_session' => [1, 100],
            'generation.max_parallel_document_jobs' => [1, 16],
            'generation.max_parallel_generation_jobs_per_organization' => [1, 8],
        ];

        foreach ($limits as $path => [$min, $max]) {
            $value = data_get($settings, $path);

            if ($value === null) {
                continue;
            }

            if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
                return false;
            }
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (! $this->validateSettings($settings)) {
            throw new InvalidArgumentException(trans_message('estimate_generation.invalid_module_settings'));
        }

        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->whereHas('module', fn ($query) => $query->where('slug', $this->getSlug()))
            ->first();

        if ($activation === null) {
            return;
        }

        $activation->update([
            'module_settings' => array_replace_recursive($activation->module_settings ?? [], $settings),
        ]);
    }

    public function getSettings(int $organizationId): array
    {
        $activation = OrganizationModuleActivation::query()
            ->where('organization_id', $organizationId)
            ->whereHas('module', fn ($query) => $query->where('slug', $this->getSlug()))
            ->first();

        if ($activation === null) {
            return $this->getDefaultSettings();
        }

        return array_replace_recursive($this->getDefaultSettings(), $activation->module_settings ?? []);
    }
}
