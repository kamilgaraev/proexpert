<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;

final class ExecutiveDocumentRequirementFixture
{
    public static function cover(ExecutiveDocumentSet $set, ExecutiveDocumentVersion $version, User $actor): void
    {
        $service = app(ExecutiveDocumentRequirementsService::class);
        $authorization = app(AuthorizationService::class);
        $type = $version->document->document_type->value;
        $requirement = $set->requirements()->whereNull('superseded_at')->where('profile_type', $type)->first();
        if ($requirement === null) {
            $payload = [
                'requirement_key' => $type, 'profile_type' => $type, 'source' => 'Утверждённый перечень ИД тестового проекта',
                'source_revision' => '1', 'coverage_scope' => ['project_id' => (int) $set->project_id],
            ];
            if (in_array($type, ['hidden_work_act', 'axis_layout_act', 'geodetic_base_acceptance_act', 'responsible_structure_act', 'engineering_network_section_act'], true)) {
                $payload['conditions'] = ['designer_supervision' => false, 'separate_executor' => false];
            }
            $requirement = $service->create($set, $payload, $actor, $authorization);
        }
        $service->attachEvidence($requirement, $version->id, ['project_id' => (int) $set->project_id], $actor, $authorization);
    }
}
