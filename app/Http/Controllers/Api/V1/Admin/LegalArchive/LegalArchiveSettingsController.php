<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowTemplate;
use App\Http\Requests\Api\V1\Admin\LegalArchive\CreateLegalArchiveWorkflowTemplateVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveTypeProfileRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveWorkflowTemplateRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveTypeProfileRequest;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\LegalArchiveDictionary;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

use function trans_message;

final class LegalArchiveSettingsController extends LegalArchiveApiController
{
    public function __construct(private readonly LegalWorkflowTemplateService $templates) {}

    public function dictionaries(Request $request): JsonResponse
    {
        try {
            $this->actor($request);

            return AdminResponse::success([
                'types' => LegalArchiveDictionary::options('types'),
                'statuses' => LegalArchiveDictionary::options('statuses'),
                'directions' => LegalArchiveDictionary::options('directions'),
                'legal_significance_statuses' => LegalArchiveDictionary::options('legal_significance_statuses'),
                'link_types' => LegalArchiveDictionary::options('link_types'),
                'version_statuses' => LegalArchiveDictionary::options('version_statuses'),
            ], trans_message('legal_archive.messages.dictionaries_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'dictionaries');
        }
    }

    public function typeProfiles(Request $request): JsonResponse
    {
        try {
            $this->actor($request);
            $standards = collect((array) config('legal-document-profiles', []))->map(
                static fn (array $profile, string $code): array => [
                    'id' => null, 'organization_id' => null, 'code' => $code, 'base_code' => $code,
                    'name' => (string) ($profile['label'] ?? $code), 'category' => (string) ($profile['category'] ?? 'other'),
                    'schema' => (array) ($profile['schema'] ?? []), 'required_fields' => (array) ($profile['required_fields'] ?? []),
                    'required_file_roles' => (array) ($profile['required_file_roles'] ?? []),
                    'requires_signature' => (bool) ($profile['requires_signature'] ?? false),
                    'allowed_signature_kinds' => (array) ($profile['allowed_signature_kinds'] ?? ['paper_original', 'external_electronic', 'provider_electronic']),
                    'required_signature_kinds' => (array) ($profile['required_signature_kinds'] ?? []),
                    'allowed_signature_formats' => (array) ($profile['allowed_signature_formats'] ?? ['detached_cades', 'embedded_cades', 'xml_dsig']),
                    'is_standard' => true, 'is_active' => true, 'lock_version' => 0,
                ],
            )->values();
            $custom = LegalArchiveDocumentTypeProfile::query()->forOrganization($this->organizationId($request))
                ->orderBy('name')->get()->map(static fn (LegalArchiveDocumentTypeProfile $profile): array => [
                    ...$profile->toArray(), 'is_standard' => false,
                ]);

            return AdminResponse::success($standards->concat($custom)->values()->all(), trans_message('legal_archive.messages.type_profiles_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profiles_index');
        }
    }

    public function storeTypeProfile(StoreLegalArchiveTypeProfileRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            if (! array_key_exists((string) $data['base_code'], (array) config('legal-document-profiles', []))) {
                throw new \DomainException('profile_base_not_found');
            }
            $profile = LegalArchiveDocumentTypeProfile::query()->create([
                ...$data, 'organization_id' => $this->organizationId($request), 'is_active' => true, 'lock_version' => 0,
            ]);

            return AdminResponse::success([...$profile->toArray(), 'is_standard' => false], trans_message('legal_archive.messages.type_profile_created'), 201);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_store');
        }
    }

    public function updateTypeProfile(UpdateLegalArchiveTypeProfileRequest $request, string $profile): JsonResponse
    {
        try {
            $updated = DB::transaction(function () use ($request, $profile): LegalArchiveDocumentTypeProfile {
                $found = LegalArchiveDocumentTypeProfile::query()->forOrganization($this->organizationId($request))
                    ->whereKey($profile)->lockForUpdate()->first();
                if (! $found instanceof LegalArchiveDocumentTypeProfile) {
                    throw new \Illuminate\Auth\Access\AuthorizationException;
                }
                $expected = (int) $request->validated('lock_version');
                if ((int) $found->lock_version !== $expected) {
                    throw new LegalArchiveLockConflict((int) $found->lock_version);
                }
                $found->forceFill([
                    ...Arr::except($request->validated(), ['lock_version']),
                    'lock_version' => $expected + 1,
                ])->save();

                return $found->refresh();
            }, 3);

            return AdminResponse::success([...$updated->toArray(), 'is_standard' => false], trans_message('legal_archive.messages.type_profile_updated'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_update', ['profile_id' => $profile]);
        }
    }

    public function workflowTemplates(Request $request): JsonResponse
    {
        try {
            $this->actor($request);
            $items = LegalWorkflowTemplate::query()->forOrganization($this->organizationId($request))
                ->with('steps')->orderBy('code')->orderByDesc('version')->get()->map(fn (LegalWorkflowTemplate $template): array => $this->templatePayload($template));

            return AdminResponse::success($items, trans_message('legal_archive.messages.workflow_templates_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_templates_index');
        }
    }

    public function storeWorkflowTemplate(StoreLegalArchiveWorkflowTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templates->createVersion(
                $this->organizationId($request), (string) $request->validated('code'), (string) $request->validated('name'),
                (array) $request->validated('steps'), $this->actor($request),
            );

            return AdminResponse::success($this->templatePayload($template), trans_message('legal_archive.messages.workflow_template_created'), 201);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_template_store');
        }
    }

    public function createWorkflowTemplateVersion(CreateLegalArchiveWorkflowTemplateVersionRequest $request, string $template): JsonResponse
    {
        try {
            $source = LegalWorkflowTemplate::query()->forOrganization($this->organizationId($request))->whereKey((int) $template)->first();
            if (! $source instanceof LegalWorkflowTemplate) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $created = $this->templates->createVersion(
                $this->organizationId($request), (string) $source->code, (string) $request->validated('name'),
                (array) $request->validated('steps'), $this->actor($request),
            );

            return AdminResponse::success($this->templatePayload($created), trans_message('legal_archive.messages.workflow_template_version_created'), 201);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_template_version', ['template_id' => $template]);
        }
    }

    private function templatePayload(LegalWorkflowTemplate $template): array
    {
        return [
            'id' => (int) $template->id, 'code' => (string) $template->code,
            'name' => (string) $template->name, 'version' => (int) $template->version,
            'definition_hash' => (string) $template->definition_hash,
            'steps' => $template->steps->map(static fn ($step): array => [
                'key' => (string) $step->step_key, 'label' => (string) $step->label,
                'sequence' => (int) $step->sequence, 'parallel_group' => (string) $step->parallel_group,
                'actor_type' => (string) $step->actor_type, 'actor_reference' => (string) $step->actor_reference,
                'required' => (bool) $step->required, 'due_in_hours' => $step->due_in_hours,
            ])->values()->all(),
        ];
    }
}
