<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowTemplate;
use App\Http\Requests\Api\V1\Admin\LegalArchive\CreateLegalArchiveWorkflowTemplateVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveTypeProfileRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveWorkflowTemplateRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveTypeProfileRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveTypeProfileResource;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveWorkflowTemplateResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\CanonicalJson;
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
                fn (array $profile, string $code): array => $this->standardProfile($code, $profile),
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

            return AdminResponse::success(LegalArchiveTypeProfileResource::collection($items)->resolve($request), trans_message('legal_archive.messages.type_profiles_loaded'), 200, [
                'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $standards->count() + $customTotal],
            ]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profiles_index');
        }
    }

    public function showTypeProfile(Request $request, string $documentTypeProfile): JsonResponse
    {
        try {
            $this->actor($request);
            $definition = ((array) config('legal-document-profiles', []))[$documentTypeProfile] ?? null;
            if (is_array($definition)) {
                $profile = $this->standardProfile($documentTypeProfile, $definition);

                return AdminResponse::success(
                    new LegalArchiveTypeProfileResource($profile),
                    trans_message('legal_archive.messages.type_profile_loaded'),
                )->withHeaders($this->profileHeaders($profile));
            }
            $profile = LegalArchiveDocumentTypeProfile::query()
                ->forOrganization($this->organizationId($request))
                ->whereKey($documentTypeProfile)
                ->first();
            if (! $profile instanceof LegalArchiveDocumentTypeProfile) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $profile->setAttribute('is_standard', false);

            return AdminResponse::success(
                new LegalArchiveTypeProfileResource($profile),
                trans_message('legal_archive.messages.type_profile_loaded'),
            )->withHeaders($this->profileHeaders($profile));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_show', ['profile_id' => $documentTypeProfile]);
        }
    }

    public function storeTypeProfile(StoreLegalArchiveTypeProfileRequest $request): JsonResponse
    {
        try {
            $profile = $this->profiles->create($this->organizationId($request), $request->validated());

            $profile->setAttribute('is_standard', false);

            return AdminResponse::success(new LegalArchiveTypeProfileResource($profile), trans_message('legal_archive.messages.type_profile_created'), 201)
                ->withHeaders($this->profileHeaders($profile));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_store');
        }
    }

    public function updateTypeProfile(UpdateLegalArchiveTypeProfileRequest $request, string $documentTypeProfile): JsonResponse
    {
        try {
            $updated = $this->profiles->update(
                $this->organizationId($request),
                $documentTypeProfile,
                (int) $request->validated('lock_version'),
                \Illuminate\Support\Arr::except($request->validated(), ['lock_version']),
            );

            $updated->setAttribute('is_standard', false);

            return AdminResponse::success(new LegalArchiveTypeProfileResource($updated), trans_message('legal_archive.messages.type_profile_updated'))
                ->withHeaders($this->profileHeaders($updated));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'type_profile_update', ['profile_id' => $documentTypeProfile]);
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
            $currentTemplateIds = DB::table('legal_workflow_template_heads')
                ->where('organization_id', $this->organizationId($request))
                ->pluck('template_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip();
            foreach ($items->getCollection() as $template) {
                $template->setAttribute('api_is_current', $currentTemplateIds->has((int) $template->id));
            }

            return AdminResponse::paginated(
                LegalArchiveWorkflowTemplateResource::collection($items->getCollection()),
                ['current_page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total(), 'last_page' => $items->lastPage()],
                trans_message('legal_archive.messages.workflow_templates_loaded'),
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_templates_index');
        }
    }

    public function showWorkflowTemplate(Request $request, string $legalWorkflowTemplate): JsonResponse
    {
        try {
            $this->actor($request);
            $template = LegalWorkflowTemplate::query()
                ->forOrganization($this->organizationId($request))
                ->with('steps')
                ->whereKey((int) $legalWorkflowTemplate)
                ->first();
            if (! $template instanceof LegalWorkflowTemplate) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $this->markCurrentTemplate($template);

            return AdminResponse::success(
                new LegalArchiveWorkflowTemplateResource($template),
                trans_message('legal_archive.messages.workflow_template_loaded'),
            )->withHeaders($this->templateHeaders($template));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_template_show', ['template_id' => $legalWorkflowTemplate]);
        }
    }

    public function storeWorkflowTemplate(StoreLegalArchiveWorkflowTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templates->createVersion(
                $this->organizationId($request), (string) $request->validated('code'), (string) $request->validated('name'),
                (array) $request->validated('steps'), $this->actor($request),
            );
            $this->markCurrentTemplate($template);

            return AdminResponse::success(new LegalArchiveWorkflowTemplateResource($template), trans_message('legal_archive.messages.workflow_template_created'), 201)
                ->withHeaders($this->templateHeaders($template));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_template_store');
        }
    }

    public function createWorkflowTemplateVersion(CreateLegalArchiveWorkflowTemplateVersionRequest $request, string $legalWorkflowTemplate): JsonResponse
    {
        try {
            $source = LegalWorkflowTemplate::query()->forOrganization($this->organizationId($request))->whereKey((int) $legalWorkflowTemplate)->first();
            if (! $source instanceof LegalWorkflowTemplate) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $created = $this->templates->createVersion(
                $this->organizationId($request), (string) $source->code, (string) $request->validated('name'),
                (array) $request->validated('steps'), $this->actor($request),
            );
            $this->markCurrentTemplate($created);

            return AdminResponse::success(new LegalArchiveWorkflowTemplateResource($created), trans_message('legal_archive.messages.workflow_template_version_created'), 201)
                ->withHeaders($this->templateHeaders($created));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_template_version', ['template_id' => $legalWorkflowTemplate]);
        }
    }

    /** @param LegalArchiveDocumentTypeProfile|array<string, mixed> $profile */
    private function profileHeaders(LegalArchiveDocumentTypeProfile|array $profile): array
    {
        $id = $profile instanceof LegalArchiveDocumentTypeProfile ? (string) $profile->id : (string) $profile['code'];
        $lockVersion = $profile instanceof LegalArchiveDocumentTypeProfile ? (int) $profile->lock_version : 0;

        return [
            'ETag' => sprintf('"legal-profile-%s-v%d"', $id, $lockVersion),
            'Location' => '/api/v1/admin/legal-archive/type-profiles/'.$id,
        ];
    }

    private function templateHeaders(LegalWorkflowTemplate $template): array
    {
        $representation = (new LegalArchiveWorkflowTemplateResource($template))->resolve(request());

        return [
            'ETag' => '"legal-workflow-template-'.CanonicalJson::fingerprint($representation).'"',
            'Location' => '/api/v1/admin/legal-archive/workflow-templates/'.(int) $template->id,
        ];
    }

    /** @param array<string, mixed> $profile */
    private function standardProfile(string $code, array $profile): array
    {
        return [
            'id' => null, 'organization_id' => null, 'code' => $code, 'base_code' => $code,
            'name' => (string) ($profile['label'] ?? $code), 'category' => (string) ($profile['category'] ?? 'other'),
            'schema' => (array) ($profile['schema'] ?? []), 'required_fields' => (array) ($profile['required_fields'] ?? []),
            'required_file_roles' => (array) ($profile['required_file_roles'] ?? []),
            'requires_signature' => (bool) ($profile['requires_signature'] ?? false),
            'allowed_signature_kinds' => (array) ($profile['allowed_signature_kinds'] ?? ['paper_original', 'external_electronic', 'provider_electronic']),
            'required_signature_kinds' => (array) ($profile['required_signature_kinds'] ?? []),
            'allowed_signature_formats' => (array) ($profile['allowed_signature_formats'] ?? ['detached_cades', 'embedded_cades', 'xml_dsig']),
            'workflow_template_id' => $profile['workflow_template_id'] ?? null,
            'retention_policy' => $profile['retention_policy'] ?? null,
            'confidentiality_level' => $profile['confidentiality_level'] ?? null,
            'is_standard' => true, 'is_active' => true, 'lock_version' => 0,
        ];
    }

    private function markCurrentTemplate(LegalWorkflowTemplate $template): void
    {
        $template->setAttribute('api_is_current', DB::table('legal_workflow_template_heads')
            ->where('organization_id', (int) $template->organization_id)
            ->where('code', (string) $template->code)
            ->where('template_id', (int) $template->id)
            ->exists());
    }
}
