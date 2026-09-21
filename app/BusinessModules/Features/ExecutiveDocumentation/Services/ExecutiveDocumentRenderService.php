<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\Exceptions\BusinessLogicException;
use Barryvdh\DomPDF\Facade\Pdf;

final class ExecutiveDocumentRenderService
{
    public const TEMPLATE_VERSION = '344-369-v1';

    public function __construct(private readonly ExecutiveDocumentMutationGuard $guard) {}

    public function snapshot(int $versionId, int $actorId): array
    {
        $version = ExecutiveDocumentVersion::query()->with('document')->find($versionId);
        $document = $version?->document;
        if ($document === null || (int) $version->organization_id !== (int) $document->organization_id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_not_found'), 404);
        }
        $this->guard->assertActor($document, $actorId, 'executive-documentation.view');
        $basis = $version->basis_snapshot ?? [];
        if (! is_array($basis['project'] ?? null) || ! is_array($basis['document'] ?? null)
            || (int) ($basis['project']['id'] ?? 0) !== (int) $document->project_id) {
            throw new BusinessLogicException(trans_message('executive_document_print.snapshot_missing'), 409);
        }
        return [
            'document_type' => $basis['document']['document_type'] ?? null,
            'document' => $basis['document'],
            'project' => $basis['project'],
            'profile_data' => $version->profile_snapshot ?? [],
            'relations' => $basis['relations'] ?? [],
            'source_version_id' => (int) $version->id,
            'source_version_number' => $version->version_number,
        ];
    }

    public function render(int $versionId, int $actorId, string $templateVersion): string
    {
        return $this->renderSnapshot($this->snapshot($versionId, $actorId), $templateVersion);
    }

    public function renderSnapshot(array $snapshot, string $templateVersion): string
    {
        if ($templateVersion !== self::TEMPLATE_VERSION) {
            throw new BusinessLogicException(trans_message('executive_document_print.template_not_supported'), 422);
        }
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 1024 * 1024) {
            throw new BusinessLogicException(trans_message('executive_document_print.render_limit'), 422);
        }
        try {
            $html = app(ExecutiveDocumentPrintTemplate::class)->html($snapshot, $templateVersion);
        } catch (\DomainException) {
            throw new BusinessLogicException(trans_message('executive_document_print.template_not_supported'), 422);
        }
        $pdf = Pdf::loadHTML($html)->setPaper('a4')->setOptions([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans',
        ]);
        $pdf->render();
        if ($pdf->getDomPDF()->getCanvas()->get_page_count() > 100) {
            throw new BusinessLogicException(trans_message('executive_document_print.render_limit'), 422);
        }
        $result = $pdf->output();
        if (strlen($result) > 25 * 1024 * 1024) {
            throw new BusinessLogicException(trans_message('executive_document_print.render_limit'), 422);
        }
        return $result;
    }
}
