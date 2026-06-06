<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignFileFormatEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignObjectTypeEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignProjectStageEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentSeverityEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignReviewCommentStatusEnum;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignArtifactVersionResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignCompletenessCheckResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignDocumentSheetResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignDocumentTemplateResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignNormativeSourceResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignPackageSectionResource;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignReviewCommentResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignDocumentArtifactService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignNormativeCatalogService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageIssueRegisterService;
use App\BusinessModules\Features\DesignManagement\Services\DesignReviewService;
use App\BusinessModules\Features\DesignManagement\Services\DesignSectionGenerationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DesignDocumentationController extends Controller
{
    public function __construct(
        private readonly DesignManagementService $managementService,
        private readonly DesignNormativeCatalogService $catalogService,
        private readonly DesignSectionGenerationService $sectionGenerationService,
        private readonly DesignDocumentArtifactService $documentArtifactService,
        private readonly DesignCompletenessService $completenessService,
        private readonly DesignReviewService $reviewService,
        private readonly DesignPackageIssueRegisterService $issueRegisterService,
    ) {
    }

    public function normativeSources(): JsonResponse
    {
        try {
            return AdminResponse::success(
                DesignNormativeSourceResource::collection($this->catalogService->sources()),
                trans_message('design_management.messages.normative_sources_loaded')
            );
        } catch (\Throwable $e) {
            return $this->failed('normative_sources', null, $e);
        }
    }

    public function documentTemplates(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'profile_code' => ['required', 'string', 'max:120'],
                'project_stage' => ['required', 'string', Rule::in($this->projectStages())],
                'object_type' => ['nullable', 'string', Rule::in($this->objectTypes())],
            ]);

            return AdminResponse::success(
                DesignDocumentTemplateResource::collection($this->catalogService->templates(
                    (string) $validated['profile_code'],
                    (string) $validated['project_stage'],
                    $validated['object_type'] ?? null
                )),
                trans_message('design_management.messages.document_templates_loaded')
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->failed('document_templates', null, $e);
        }
    }

    public function generateSections(Request $request, int $packageId): JsonResponse
    {
        try {
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                DesignPackageSectionResource::collection($this->sectionGenerationService->generateForPackage($package)),
                trans_message('design_management.messages.sections_generated')
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('generate_sections', $packageId, $e);
        }
    }

    public function sections(Request $request, int $packageId): JsonResponse
    {
        try {
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                DesignPackageSectionResource::collection($this->sectionGenerationService->sectionsForPackage($package)),
                trans_message('design_management.messages.sections_loaded')
            );
        } catch (\Throwable $e) {
            return $this->failed('sections', $packageId, $e);
        }
    }

    public function uploadDocument(Request $request, int $packageId, int $sectionId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:' . $this->maxDocumentSizeKilobytes(), $this->allowedDocumentExtensionRule()],
                'title' => ['required', 'string', 'max:255'],
                'document_code' => ['required', 'string', 'max:80'],
                'document_title' => ['nullable', 'string', 'max:255'],
                'artifact_type' => ['nullable', 'string', Rule::in($this->artifactTypes())],
                'version_number' => ['required', 'string', 'max:80'],
                'revision' => ['nullable', 'string', 'max:80'],
                'revision_label' => ['nullable', 'string', 'max:80'],
                'requires_sheet_registry' => ['nullable', 'boolean'],
                'make_current' => ['nullable', 'boolean'],
                'metadata' => ['nullable', 'array'],
                'artifact_metadata' => ['nullable', 'array'],
            ]);
            $section = $this->section($request, $packageId, $sectionId);

            if ($section === null) {
                return AdminResponse::error(trans_message('design_management.errors.section_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignArtifactVersionResource($this->documentArtifactService->uploadDocument(
                    $section,
                    (int) auth()->id(),
                    $validated['file'],
                    $validated
                )),
                trans_message('design_management.messages.document_uploaded'),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('upload_document', $packageId, $e);
        }
    }

    public function replaceSheets(Request $request, int $versionId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'sheets' => ['required', 'array', 'min:1', 'max:1000'],
                'sheets.*.sheet_number' => ['required', 'string', 'max:80'],
                'sheets.*.sheet_code' => ['nullable', 'string', 'max:120'],
                'sheets.*.sheet_title' => ['required', 'string', 'max:255'],
                'sheets.*.revision' => ['nullable', 'string', 'max:80'],
                'sheets.*.file_page_number' => ['nullable', 'integer', 'min:1'],
                'sheets.*.total_sheets' => ['nullable', 'integer', 'min:1'],
                'sheets.*.status' => ['nullable', 'string', 'max:80'],
                'sheets.*.metadata' => ['nullable', 'array'],
            ]);
            $version = $this->documentArtifactService->findVersion($this->organizationId($request), $versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('design_management.errors.version_not_found'), 404);
            }

            $version = $this->documentArtifactService->replaceSheets($version, (int) auth()->id(), $validated['sheets']);

            return AdminResponse::success(
                DesignDocumentSheetResource::collection($version->sheets),
                trans_message('design_management.messages.sheets_updated')
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('replace_sheets', $versionId, $e);
        }
    }

    public function runCompletenessCheck(Request $request, int $packageId): JsonResponse
    {
        try {
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignCompletenessCheckResource($this->completenessService->run($package, (int) auth()->id())),
                trans_message('design_management.messages.completeness_checked'),
                201
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('run_completeness_check', $packageId, $e);
        }
    }

    public function reviewComments(Request $request, int $packageId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => ['nullable', 'string', Rule::in($this->reviewCommentStatuses())],
                'severity' => ['nullable', 'string', Rule::in($this->reviewCommentSeverities())],
            ]);
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                DesignReviewCommentResource::collection($this->reviewService->commentsForPackage($package, $validated)),
                trans_message('design_management.messages.review_comments_loaded')
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->failed('review_comments', $packageId, $e);
        }
    }

    public function storeReviewComment(Request $request, int $packageId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'review_type' => ['nullable', 'string', 'max:80'],
                'section_id' => ['nullable', 'integer'],
                'artifact_id' => ['nullable', 'integer'],
                'version_id' => ['nullable', 'integer'],
                'sheet_id' => ['nullable', 'integer'],
                'assignee_id' => ['nullable', 'integer'],
                'severity' => ['required', 'string', Rule::in($this->reviewCommentSeverities())],
                'body' => ['required', 'string', 'max:4000'],
                'bim_element_id' => ['nullable', 'string', 'max:255'],
                'due_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignReviewCommentResource($this->reviewService->createComment($package, (int) auth()->id(), $validated)),
                trans_message('design_management.messages.review_comment_created'),
                201
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('store_review_comment', $packageId, $e);
        }
    }

    public function updateReviewComment(Request $request, int $commentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'assignee_id' => ['nullable', 'integer'],
                'severity' => ['nullable', 'string', Rule::in($this->reviewCommentSeverities())],
                'status' => ['required', 'string', Rule::in($this->reviewCommentStatuses())],
                'body' => ['nullable', 'string', 'max:4000'],
                'response' => ['nullable', 'string', 'max:4000'],
                'due_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);
            $comment = $this->reviewService->findComment($this->organizationId($request), $commentId);

            if ($comment === null) {
                return AdminResponse::error(trans_message('design_management.errors.review_comment_not_found'), 404);
            }

            return AdminResponse::success(
                new DesignReviewCommentResource($this->reviewService->updateComment($comment, (int) auth()->id(), $validated)),
                trans_message('design_management.messages.review_comment_updated')
            );
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->failed('update_review_comment', $commentId, $e);
        }
    }

    public function issueRegister(Request $request, int $packageId): JsonResponse
    {
        try {
            $package = $this->package($request, $packageId);

            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }

            return AdminResponse::success(
                $this->issueRegisterService->build($package),
                trans_message('design_management.messages.issue_register_loaded')
            );
        } catch (\Throwable $e) {
            return $this->failed('issue_register', $packageId, $e);
        }
    }

    public function downloadDocumentSourceFile(Request $request, int $versionId): JsonResponse|StreamedResponse
    {
        try {
            $version = $this->documentArtifactService->findVersion($this->organizationId($request), $versionId);

            if ($version === null) {
                return AdminResponse::error(trans_message('design_management.errors.version_not_found'), 404);
            }

            return $this->streamFile($this->documentArtifactService->sourceFileStream($version));
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed('download_document_source_file', $versionId, $e);
        }
    }

    private function package(Request $request, int $packageId)
    {
        return $this->managementService->findPackage($this->organizationId($request), $packageId);
    }

    private function section(Request $request, int $packageId, int $sectionId): ?DesignPackageSection
    {
        return DesignPackageSection::forOrganization($this->organizationId($request))
            ->where('package_id', $packageId)
            ->with('package')
            ->find($sectionId);
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function allowedDocumentExtensionRule(): callable
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (!$value instanceof UploadedFile) {
                $fail(trans_message('design_management.errors.document_file_required'));
                return;
            }

            $extension = strtolower($value->getClientOriginalExtension());

            if (!in_array($extension, DesignFileFormatEnum::values(), true) || $extension === 'frag') {
                $fail(trans_message('design_management.errors.document_file_required'));
            }
        };
    }

    private function maxDocumentSizeKilobytes(): int
    {
        return 1024 * 1024;
    }

    private function artifactTypes(): array
    {
        return array_map(static fn (DesignArtifactTypeEnum $type): string => $type->value, DesignArtifactTypeEnum::cases());
    }

    private function projectStages(): array
    {
        return array_map(static fn (DesignProjectStageEnum $stage): string => $stage->value, DesignProjectStageEnum::cases());
    }

    private function objectTypes(): array
    {
        return array_map(static fn (DesignObjectTypeEnum $type): string => $type->value, DesignObjectTypeEnum::cases());
    }

    private function reviewCommentStatuses(): array
    {
        return array_map(static fn (DesignReviewCommentStatusEnum $status): string => $status->value, DesignReviewCommentStatusEnum::cases());
    }

    private function reviewCommentSeverities(): array
    {
        return array_map(static fn (DesignReviewCommentSeverityEnum $severity): string => $severity->value, DesignReviewCommentSeverityEnum::cases());
    }

    private function validationFailed(ValidationException $e): JsonResponse
    {
        return AdminResponse::error(trans_message('design_management.errors.validation_failed'), 422, $e->errors());
    }

    /**
     * @param array{stream: resource, filename: string, mime_type: string} $file
     */
    private function streamFile(array $file): StreamedResponse
    {
        $stream = $file['stream'];

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            (string) $file['filename'],
            [
                'Content-Type' => (string) $file['mime_type'],
                'Cache-Control' => 'no-store',
            ]
        );
    }

    private function failed(string $action, ?int $id, \Throwable $e): JsonResponse
    {
        Log::error("design_management.documentation.{$action}.error", [
            'action' => $action,
            'id' => $id,
            'user_id' => auth()->id(),
            'organization_id' => request()?->attributes->get('current_organization_id'),
            'error' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message("design_management.errors.{$action}_failed"), 500);
    }
}
