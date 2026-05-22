<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class PaginatedResourceCollection extends ResourceCollection
{
    protected function paginator(): LengthAwarePaginator
    {
        assert($this->resource instanceof LengthAwarePaginator);

        return $this->resource;
    }

    protected function currentPage(): int
    {
        return $this->paginator()->currentPage();
    }

    protected function firstItem(): ?int
    {
        return $this->paginator()->firstItem();
    }

    protected function lastItem(): ?int
    {
        return $this->paginator()->lastItem();
    }

    protected function lastPage(): int
    {
        return $this->paginator()->lastPage();
    }

    protected function path(): string
    {
        return $this->paginator()->path();
    }

    protected function perPage(): int
    {
        return $this->paginator()->perPage();
    }

    protected function total(): int
    {
        return $this->paginator()->total();
    }

    protected function url(int $page): string
    {
        return $this->paginator()->url($page);
    }

    protected function previousPageUrl(): ?string
    {
        return $this->paginator()->previousPageUrl();
    }

    protected function nextPageUrl(): ?string
    {
        return $this->paginator()->nextPageUrl();
    }

    protected function hasMorePages(): bool
    {
        return $this->paginator()->hasMorePages();
    }
}
