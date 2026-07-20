<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

final readonly class LegalDocumentTypeProfileService
{
    public function __construct(
        private ConnectionInterface $connection,
        private LegalDocumentProfileRegistry $registry,
        private LegalDocumentProfileValidator $validator,
    ) {}

    public function create(int $organizationId, array $data): LegalArchiveDocumentTypeProfile
    {
        return $this->connection->transaction(function () use ($organizationId, $data): LegalArchiveDocumentTypeProfile {
            $this->assertInput($data);
            $this->assertWorkflowTemplate($organizationId, $data['workflow_template_id'] ?? null);
            if (LegalArchiveDocumentTypeProfile::query()->forOrganization($organizationId)
                ->where('code', (string) $data['code'])->lockForUpdate()->exists()) {
                throw new DomainException('profile_code_duplicate');
            }
            try {
                $profile = LegalArchiveDocumentTypeProfile::query()->create([
                    ...$data,
                    'organization_id' => $organizationId,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'lock_version' => 0,
                ]);
            } catch (QueryException $error) {
                throw new DomainException('profile_code_duplicate', previous: $error);
            }
            if ((bool) $profile->is_active) {
                $this->assertResolved($organizationId, (string) $profile->code);
            }

            return $profile->refresh();
        }, 3);
    }

    public function update(int $organizationId, string $id, int $expectedLockVersion, array $changes): LegalArchiveDocumentTypeProfile
    {
        return $this->connection->transaction(function () use ($organizationId, $id, $expectedLockVersion, $changes): LegalArchiveDocumentTypeProfile {
            $this->assertInput($changes);
            $profile = LegalArchiveDocumentTypeProfile::query()->forOrganization($organizationId)
                ->whereKey($id)->lockForUpdate()->first();
            if (! $profile instanceof LegalArchiveDocumentTypeProfile) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $this->assertInput([
                ...$profile->only(['required_fields', 'required_file_roles', 'allowed_signature_kinds', 'required_signature_kinds', 'allowed_signature_formats']),
                ...$changes,
            ]);
            if ((int) $profile->lock_version !== $expectedLockVersion) {
                throw new LegalArchiveLockConflict((int) $profile->lock_version);
            }
            $this->assertWorkflowTemplate($organizationId, $changes['workflow_template_id'] ?? $profile->workflow_template_id);
            $profile->forceFill([
                ...$changes,
                'code' => (string) $profile->code,
                'base_code' => (string) $profile->base_code,
                'lock_version' => $expectedLockVersion + 1,
            ])->save();
            if ((bool) $profile->is_active) {
                $this->assertResolved($organizationId, (string) $profile->code);
            }

            return $profile->refresh();
        }, 3);
    }

    private function assertResolved(int $organizationId, string $code): void
    {
        try {
            $this->validator->assertDefinition($this->registry->find($organizationId, $code));
        } catch (\InvalidArgumentException $error) {
            throw new DomainException('profile_definition_invalid', previous: $error);
        }
    }

    private function assertWorkflowTemplate(int $organizationId, mixed $templateId): void
    {
        if ($templateId === null) {
            return;
        }
        $exists = $this->connection->table('legal_workflow_template_heads as head')
            ->join('legal_workflow_templates as template', 'template.id', '=', 'head.template_id')
            ->where('head.organization_id', $organizationId)
            ->where('template.organization_id', $organizationId)
            ->where('template.id', (int) $templateId)
            ->exists();
        if (! $exists) {
            throw new DomainException('profile_workflow_template_invalid');
        }
    }

    private function assertInput(array $data): void
    {
        foreach (['required_fields', 'required_file_roles', 'allowed_signature_kinds', 'required_signature_kinds', 'allowed_signature_formats'] as $key) {
            if (isset($data[$key]) && (! is_array($data[$key]) || ! array_is_list($data[$key])
                || array_filter($data[$key], static fn (mixed $value): bool => ! is_string($value) || trim($value) === '') !== [])) {
                throw new DomainException('profile_definition_invalid');
            }
        }
        $allowedKinds = (array) ($data['allowed_signature_kinds'] ?? ['paper_original', 'external_electronic', 'provider_electronic']);
        $requiredKinds = (array) ($data['required_signature_kinds'] ?? []);
        if (array_diff($allowedKinds, ['paper_original', 'external_electronic', 'provider_electronic']) !== []
            || array_diff($requiredKinds, $allowedKinds) !== []
            || array_diff((array) ($data['allowed_signature_formats'] ?? []), ['detached_cades', 'embedded_cades', 'xml_dsig']) !== []) {
            throw new DomainException('profile_definition_invalid');
        }
    }
}
