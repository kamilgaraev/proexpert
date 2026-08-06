<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportDownloadLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportDownloadLink);

        return [
            'url' => $this->resource->url,
            'storage_key' => $this->resource->storageKey,
            'expires_at' => $this->resource->expiresAt->format(DATE_ATOM),
        ];
    }
}
