<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\Exceptions\BusinessLogicException;
use App\Services\Storage\FileService;
use ZipArchive;

final class ExecutiveDocumentPrintPackageService
{
    public function __construct(private readonly ExecutiveDocumentMutationGuard $guard, private readonly FileService $files) {}

    public function build(int $transmittalId, int $actorId): string
    {
        $transmittal = ExecutiveDocumentTransmittal::query()->findOrFail($transmittalId);
        $entries = $transmittal->manifest['documents'] ?? [];
        if (! is_array($entries) || $entries === [] || count($entries) > 200 || empty($transmittal->manifest_hash)) {
            $this->invalid();
        }
        $versions = ExecutiveDocumentVersion::query()->with('document')->whereIn('id', array_column($entries, 'version_id'))->get()->keyBy('id');
        foreach ($entries as $entry) {
            $version = $versions->get($entry['version_id'] ?? null);
            $document = $version?->document;
            if ($document === null || (int) $version->organization_id !== (int) $transmittal->organization_id
                || (int) $document->organization_id !== (int) $transmittal->organization_id
                || (int) $document->document_set_id !== (int) $transmittal->document_set_id
                || (int) $document->id !== (int) ($entry['document_id'] ?? 0)
                || $version->content_hash !== ($entry['content_hash'] ?? null)
                || $version->file_url !== ($entry['file_url'] ?? null)) {
                $this->invalid();
            }
            $this->guard->assertActor($document, $actorId, 'executive-documentation.view');
        }
        $path = tempnam(sys_get_temp_dir(), 'itd-package-');
        if ($path === false) {
            throw new \RuntimeException('executive_package_temp_failed');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('executive_package_open_failed');
            }
            $opened = true;
            $rows = [];
            $total = 0;
            foreach ($entries as $entry) {
                $key = (string) $entry['file_url'];
                if (! str_starts_with($key, 'org-'.$transmittal->organization_id.'/') || str_contains($key, '..')) {
                    $this->invalid();
                }
                $stream = $this->files->disk()->readStream($key);
                if (! is_resource($stream)) {
                    $this->invalid();
                }
                try {
                    $bytes = stream_get_contents($stream, 25 * 1024 * 1024 + 1);
                } finally {
                    fclose($stream);
                }
                if (! is_string($bytes) || strlen($bytes) > 25 * 1024 * 1024
                    || ! hash_equals((string) $entry['content_hash'], hash('sha256', $bytes))) {
                    $this->invalid();
                }
                $total += strlen($bytes);
                if ($total > 64 * 1024 * 1024) {
                    throw new BusinessLogicException(trans_message('executive_document_print.render_limit'), 422);
                }
                $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                $extension = in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'], true) ? $extension : 'bin';
                $filename = 'documents/'.(int) $entry['version_id'].'.'.$extension;
                if (! $zip->addFromString($filename, $bytes)) {
                    throw new \RuntimeException('executive_package_entry_failed');
                }
                $rows[] = ['document_id' => (int) $entry['document_id'], 'version_id' => (int) $entry['version_id'],
                    'version_number' => $entry['version_number'], 'title' => $entry['title'], 'sha256' => $entry['content_hash'], 'file' => $filename];
            }
            $approvedList = $transmittal->manifest['approved_list'] ?? null;
            $approvedListEntry = null;
            if (is_array($approvedList)) {
                $key = (string) ($approvedList['file_url'] ?? '');
                if (! str_starts_with($key, 'org-'.$transmittal->organization_id.'/') || str_contains($key, '..')) {
                    $this->invalid();
                }
                $stream = $this->files->disk()->readStream($key);
                if (! is_resource($stream)) {
                    $this->invalid();
                }
                try {
                    $bytes = stream_get_contents($stream, 25 * 1024 * 1024 + 1);
                } finally {
                    fclose($stream);
                }
                if (! is_string($bytes) || strlen($bytes) > 25 * 1024 * 1024
                    || ! hash_equals((string) ($approvedList['file_hash'] ?? ''), hash('sha256', $bytes))) {
                    $this->invalid();
                }
                $total += strlen($bytes);
                if ($total > 64 * 1024 * 1024) {
                    throw new BusinessLogicException(trans_message('executive_document_print.render_limit'), 422);
                }
                $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                $extension = in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true) ? $extension : 'bin';
                $filename = 'approved-list/revision-'.(int) ($approvedList['revision'] ?? 0).'.'.$extension;
                if (! $zip->addFromString($filename, $bytes)) {
                    throw new \RuntimeException('executive_package_entry_failed');
                }
                $approvedListEntry = ['revision' => $approvedList['revision'], 'sha256' => $approvedList['file_hash'], 'file' => $filename];
            }
            $registry = ['transmittal_id' => (int) $transmittal->id, 'number' => $transmittal->transmittal_number,
                'manifest_hash' => $transmittal->manifest_hash, 'documents' => $rows, 'approved_list' => $approvedListEntry];
            $zip->addFromString('registry.json', json_encode($registry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->addFromString('registry.html', view('executive-documentation.package-registry', ['registry' => $registry])->render());
            $zip->close();
            $opened = false;
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new \RuntimeException('executive_package_read_failed');
            }
            return $bytes;
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function invalid(): never
    {
        throw new BusinessLogicException(trans_message('executive_document_print.package_invalid'), 409);
    }
}
