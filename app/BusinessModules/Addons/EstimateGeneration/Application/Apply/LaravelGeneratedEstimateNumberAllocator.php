<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaravelGeneratedEstimateNumberAllocator implements GeneratedEstimateNumberAllocator
{
    private const MAX_NUMBER_LENGTH = 255;

    private Closure $suffixFactory;

    public function __construct(?Closure $suffixFactory = null)
    {
        $this->suffixFactory = $suffixFactory ?? static fn (): string => (string) Str::ulid();
    }

    public function allocate(EstimateGenerationSession $session, int $attempt): string
    {
        $base = sprintf('AI-%d', (int) $session->getKey());

        if ($attempt === 0 && ! $this->isOccupied((int) $session->organization_id, $base)) {
            return $base;
        }

        $suffix = mb_substr(trim((string) ($this->suffixFactory)()), 0, 64);
        if ($suffix === '') {
            $suffix = (string) Str::ulid();
        }
        $availableBaseLength = self::MAX_NUMBER_LENGTH - mb_strlen($suffix) - 1;

        return mb_substr($base, 0, $availableBaseLength).'-'.$suffix;
    }

    protected function isOccupied(int $organizationId, string $number): bool
    {
        return DB::table('estimates')
            ->where('organization_id', $organizationId)
            ->where('number', $number)
            ->exists();
    }
}
