<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class PreparedDocumentAdmission
{
    /** @param list<UnpublishedDocumentObject> $objects @param Collection<int, EstimateGenerationDocument>|null $sources */
    public function __construct(public array $objects, public ?Collection $sources = null) {}

    /** @param list<UploadedFile> $files */
    public static function upload(EstimateGenerationSession $session, array $files): self
    {
        return new self(array_map(static function (UploadedFile $file) use ($session): UnpublishedDocumentObject {
            $checksum = hash_file('sha256', $file->getPathname());
            if (! is_string($checksum)) {
                throw new \InvalidArgumentException('document_read_failed');
            }

            return self::object($session, $file->getClientOriginalExtension(), $checksum, (int) $file->getSize());
        }, $files));
    }

    /** @param Collection<int, EstimateGenerationDocument> $sources */
    public static function reuse(EstimateGenerationSession $session, Collection $sources): self
    {
        $objects = $sources->map(static function (EstimateGenerationDocument $source) use ($session): UnpublishedDocumentObject {
            $extension = strtolower((string) ($source->meta['original_extension'] ?? pathinfo($source->filename, PATHINFO_EXTENSION)));

            return self::object($session, $extension, (string) $source->checksum_sha256, (int) $source->file_size_bytes);
        })->values()->all();

        return new self($objects, $sources);
    }

    /** @return list<array{path:string,sha256:string,size:int}> */
    public function snapshot(): array
    {
        return array_map(static fn (UnpublishedDocumentObject $object): array => [
            'path' => $object->path, 'sha256' => $object->sha256, 'size' => $object->sizeBytes,
        ], $this->objects);
    }

    /** @param list<array{path:string,sha256:string,size:int}> $snapshot */
    public static function batch(EstimateGenerationSession $session, array $snapshot): UnpublishedDocumentObjectBatch
    {
        $batch = new UnpublishedDocumentObjectBatch((int) $session->organization_id, (int) $session->project_id, (int) $session->id);
        foreach ($snapshot as $object) {
            $batch->capture($object['path'], $object['sha256'], $object['size']);
        }

        return $batch;
    }

    private static function object(EstimateGenerationSession $session, string $extension, string $checksum, int $size): UnpublishedDocumentObject
    {
        $path = 'org-'.$session->organization_id.'/estimate-generation/sessions/'.$session->id.'/documents/'.Str::uuid().'.'.$extension;

        return new UnpublishedDocumentObject((int) $session->organization_id, (int) $session->project_id, (int) $session->id, $path, $checksum, $size);
    }
}
