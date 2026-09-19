<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\Log;

final class ContractBuilderExportService
{
    public function __construct(
        private readonly ContractBuilderInstanceService $instances,
        private readonly ContractDocumentExporter $exporter,
        private readonly FileService $files,
    ) {}

    public function export(User $actor, int $organizationId, int $contractId, int $revisionNumber, string $format): array
    {
        if (!in_array($format, ['docx', 'pdf'], true)) {
            throw new ContractBuilderException('contracts.builder_export_invalid', 422);
        }
        $preview = $this->instances->preview($actor, $organizationId, $contractId, $revisionNumber);
        $revision = $preview['revision'];
        $organization = Organization::findOrFail($organizationId);
        $filename = 'contract-'.$contractId.'-revision-'.$revisionNumber.'.'.$format;
        try {
            $bytes = $this->exporter->render($preview['html'], $format);
            $path = $this->files->putContent($bytes, 'contracts/'.$contractId.'/builder/exports/v1/'.$revision['content_hash'], $filename, 'private', $organization);
            if ($path === false) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }
            $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $url = $this->files->temporaryUrl($path, 5, $organization, ['ResponseContentType' => $mime,
                'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"']);
            if ($url === null) {
                throw new ContractBuilderException('contracts.builder_export_failed', 503);
            }

            return ['revision_number' => $revisionNumber, 'content_hash' => $revision['content_hash'], 'format' => $format,
                'filename' => $filename, 'url' => $url, 'expires_in' => 300, 'size' => strlen($bytes)];
        } catch (ContractBuilderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('contract_builder_export_failed', ['organization_id' => $organizationId, 'contract_id' => $contractId,
                'revision' => $revisionNumber, 'format' => $format, 'exception_type' => $exception::class]);
            throw new ContractBuilderException('contracts.builder_export_failed', 503);
        }
    }
}
