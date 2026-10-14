<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\Organization;
use App\Models\User;
use App\Enums\OrganizationCapability;
use App\Services\Organization\OrganizationProfileService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationProfileReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_business_profile_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/организац/iu', $query) === 1 && preg_match('/заполнен[а-яё]*\s+профил|профил[а-яё]*.*(?:заполнен|деятельност)|основн[а-яё]*\s+настройк[а-яё]*.*деятельност|специализац|сертификац/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/измени|обнови|заполни\b|отправь|удали|\bправ[а-яё]*|уведомлен|адрес|телефон|почт|договор|сч[её]т|выгруз|проект|сотрудник/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $organization = Organization::query()->whereKey($organizationId)->first(['id', 'name', 'capabilities', 'primary_business_type', 'specializations',
            'certifications', 'profile_completeness', 'onboarding_completed', 'onboarding_completed_at']);
        if ($organization === null) { throw new AuthorizationException; }
        $profile = app(OrganizationProfileService::class)->getProfile($organization);
        return ['name' => $organization->name, 'capabilities' => $profile->getCapabilities(),
            'primary_business_type' => $profile->getPrimaryBusinessType()?->value, 'specializations' => $profile->getSpecializations(),
            'certifications_count' => count($profile->getCertifications()), 'profile_completeness' => $profile->getProfileCompleteness(),
            'onboarding_completed' => $profile->isOnboardingCompleted(), 'onboarding_completed_at' => $profile->getOnboardingCompletedAt()?->format('Y-m-d H:i:s')];
    }

    protected function format(array $data): string
    {
        $label = static fn (string $value): string => trans_message('ai_assistant_operational.organization_capability_'.(OrganizationCapability::tryFrom($value) === null ? 'unknown' : $value));
        $lines = [trans_message('ai_assistant_operational.organization_profile_scope', ['name' => self::text($data['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.organization_profile_completeness', ['value' => $data['profile_completeness']]),
            trans_message('ai_assistant_operational.organization_profile_primary', ['value' => $data['primary_business_type'] === null ? trans_message('ai_assistant_operational.not_recorded') : $label($data['primary_business_type'])]),
            trans_message('ai_assistant_operational.organization_profile_capabilities', ['value' => $data['capabilities'] === [] ? trans_message('ai_assistant_operational.not_recorded') : implode(', ', array_map($label, $data['capabilities']))]),
            trans_message('ai_assistant_operational.organization_profile_details', ['specializations' => count($data['specializations']), 'certifications' => $data['certifications_count'],
                'onboarding' => trans_message('ai_assistant_operational.permission_'.($data['onboarding_completed'] ? 'yes' : 'no'))])];
        foreach (array_slice($data['specializations'], 0, 20) as $specialization) {
            if (is_string($specialization)) { $lines[] = '- '.self::text($specialization); }
        }
        $lines[] = trans_message('ai_assistant_operational.organization_profile_basis');
        return implode("\n", $lines);
    }
}
