<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationVerificationService;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationVerificationReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_verification_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/организац/iu', $query) === 1 && preg_match('/подтвержден|подтвержд[её]н|верификац|статус[а-яё]*\s+проверк/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/запусти|подтверди\b|проверь\b|отправь|измени|удали|заполни\b|\bправ[а-яё]*|уведомлен|выгруз|сотрудник|договор|проект/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $organization = Organization::query()->whereKey($organizationId)->first();
        if ($organization === null) { throw new AuthorizationException; }
        $native = app(OrganizationVerificationService::class)->getVerificationRecommendations($organization);
        $fields = static fn (array $rows): array => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['field', 'name', 'required', 'weight'])), $rows);
        return ['name' => $organization->name, 'status' => $native['status'], 'status_text' => $native['status_text'],
            'score' => $native['current_score'], 'verified' => $native['status'] === 'verified',
            'verified_at' => $native['status'] === 'verified' ? $organization->verified_at?->toIso8601String() : null,
            'missing_fields' => $fields($native['missing_fields']), 'field_issues' => $fields($native['field_issues']),
            'verification_issue_count' => count($native['verification_issues']), 'needs_verification' => $native['needs_verification']];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.organization_verification_scope', ['name' => self::text($data['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.organization_verification_status', ['status' => self::text($data['status_text']), 'score' => $data['score']])];
        foreach (['missing_fields', 'field_issues'] as $section) {
            $lines[] = trans_message('ai_assistant_operational.organization_verification_'.$section, ['count' => count($data[$section])]);
            foreach ($data[$section] as $row) { $lines[] = '- '.self::text($row['name']); }
        }
        if ($data['needs_verification']) { $lines[] = trans_message('ai_assistant_operational.organization_verification_request_needed'); }
        $lines[] = trans_message('ai_assistant_operational.organization_verification_external_issues', ['count' => $data['verification_issue_count']]);
        $lines[] = trans_message('ai_assistant_operational.organization_verification_basis');
        return implode("\n", $lines);
    }
}
