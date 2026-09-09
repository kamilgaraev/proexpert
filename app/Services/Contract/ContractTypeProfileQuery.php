<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use Illuminate\Auth\Access\AuthorizationException;

final class ContractTypeProfileQuery
{
    public function get(int $organizationId, int $page, int $perPage): array
    {
        if ($organizationId < 1) {
            throw new AuthorizationException;
        }

        $standards = collect((array) config('legal-document-profiles', []))
            ->filter(static fn (array $profile): bool => ($profile['category'] ?? null) === 'contract');
        $standardOptions = $standards->map(static fn (array $profile, string $code): array => [
            'code' => $code,
            'name' => (string) $profile['label'],
            'category' => 'contract',
            'is_active' => true,
            'is_standard' => true,
        ])->values();
        $query = LegalArchiveDocumentTypeProfile::query()->forOrganization($organizationId)
            ->active()->whereIn('base_code', $standards->keys()->all());
        $total = $standardOptions->count() + (clone $query)->count();
        $offset = ($page - 1) * $perPage;
        $items = $standardOptions->slice($offset, $perPage)->values();
        $remaining = $perPage - $items->count();

        if ($remaining > 0) {
            $custom = $query->orderBy('name')->orderBy('id')
                ->offset(max(0, $offset - $standardOptions->count()))->limit($remaining)
                ->get(['id', 'code', 'name'])
                ->map(static fn (LegalArchiveDocumentTypeProfile $profile): array => [
                    'code' => $profile->code,
                    'name' => $profile->name,
                    'category' => 'contract',
                    'is_active' => true,
                    'is_standard' => false,
                ]);
            $items = $items->concat($custom)->values();
        }

        return ['items' => $items->all(), 'total' => $total];
    }
}
