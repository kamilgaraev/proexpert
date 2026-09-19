<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use Illuminate\Http\UploadedFile;

class ContractRevisionArchivePublisher
{
    public function __construct(private readonly LegalArchiveRegistryService $archive) {}

    public function publish(int $organizationId, int $actorId, array $data, UploadedFile $file): LegalArchiveDocument
    {
        return $this->archive->create($organizationId, $actorId, $data, $file);
    }
}
