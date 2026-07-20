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
use App\Services\LegalArchive\Profiles\LegalDocumentTypeProfileService;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

use function trans_message;

final class LegalArchiveSettingsController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalWorkflowTemplateService $templates,
        private readonly LegalDocumentTypeProfileService $profiles,
    ) {}

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
            $perPage = max(1, min($request->integer('per_page', 50), 100));
            $page = max(1, $request->integer('page', 1));
            $offset = ($page - 1) * $perPage;
            $standardPage = $standards->slice($offset, $perPage)->values();
            $customQuery = LegalArchiveDocumentTypeProfile::query()->forOrganization($this->organizationId($request));
            $customTotal = (clone $customQuery)->count();
            $remaining = $perPage - $standardPage->count();
            $custom = collect();
            if ($remaining > 0) {
                $custom = $customQuery->orderBy('name')->orderBy('id')
                    ->offset(max(0, $offset - $standards->count()))->limit($remaining)->get()
                    ->map(static fn (LegalArchiveDocumentTypeProfile $profile): array => [...$profile->toArray(), 'is_standard' => false]);
            }
            $items = $standardPage->concat($custom)->values();

            return AdminResponse::success($items->all(), trans_message('legal_archive.messages.type_profiles_loaded'), 200, [
                'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $standards->count() + $customTotal],
            ]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profiles_index');
        }
    }

    public function storeTypeProfile(StoreLegalArchiveTypeProfileRequest $request): JsonResponse
    {
        try {
            $profile = $this->profiles->create($this->organizationId($request), $request->validated());

            return AdminResponse::success([...$profile->toArray(), 'is_standard' => false], trans_message('legal_archive.messages.type_profile_created'), 201)
                ->withHeaders($this->profileHeaders($profile));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_store');
        }
    }

    public function updateTypeProfile(UpdateLegalArchiveTypeProfileRequest $request, string $profile): JsonResponse
    {
        try {
            $updated = $this->profiles->update(
                $this->organizationId($request),
                $profile,
                (int) $request->validated('lock_version'),
                \Illuminate\Support\Arr::except($request->validated(), ['lock_version']),
            );

            return AdminResponse::success([...$updated->toArray(), 'is_standard' => false], trans_message('legal_archive.messages.type_profile_updated'))
                ->withHeaders($this->profileHeaders($updated));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_update', ['profile_id' => $profile]);
        }
    }

    public function workflowTemplates(Request $request): JsonResponse
    {
        try {
            $this->actor($request);
            $perPage = max(1, min($request->integer('per_page', 25), 100));
            $query = LegalWorkflowTemplate::query()->forOrganization($this->organizationId($request))->with('steps');
            if (! $request->boolean('all_versions')) {
                $query->whereIn('id', DB::table('legal_workflow_template_heads')
                    ->where('organization_id', $this->organizationId($request))->select('template_id'));
            }
            $items = $query->orderBy('code')->orderByDesc('version')->paginate($perPage);

            return AdminResponse::paginated(
                $items->getCollection()->map(fn (LegalWorkflowTemplate $template): array => $this->templatePayload($template))->all(),
                ['current_page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total(), 'last_page' => $items->lastPage()],
                trans_message('legal_archive.messages.workflow_templates_loaded'),
            );
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

            return AdminResponse::success($this->templatePayload($template), trans_message('legal_archive.messages.workflow_template_created'), 201)
                ->withHeaders($this->templateHeaders($template));
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

            return AdminResponse::success($this->templatePayload($created), trans_message('legal_archive.messages.workflow_template_version_created'), 201)
                ->withHeaders($this->templateHeaders($created));
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
                'policy_key' => $step->policy_key, 'settings' => (array) $step->settings,
            ])->values()->all(),
        ];
    }

    private function profileHeaders(LegalArchiveDocumentTypeProfile $profile): array
    {
        return [
            'ETag' => sprintf('"legal-profile-%s-v%d"', $profile->id, $profile->lock_version),
            'Location' => '/api/v1/admin/legal-archive/type-profiles/'.(string) $profile->id,
        ];
    }

    private function templateHeaders(LegalWorkflowTemplate $template): array
    {
        return [
            'ETag' => '"legal-workflow-template-'.(string) $template->definition_hash.'"',
            'Location' => '/api/v1/admin/legal-archive/workflow-templates/'.(int) $template->id,
        ];
    }
}
