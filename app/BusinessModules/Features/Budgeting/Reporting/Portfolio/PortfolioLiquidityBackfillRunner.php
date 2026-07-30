<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityBackfillCheckpoint;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class PortfolioLiquidityBackfillRunner
{
    private const LEASE_SECONDS = 300;

    public function __construct(private PortfolioLiquiditySourceVersionBackfill $backfill) {}

    public function runChunk(int $organizationId, string $sourceType, int $limit = 500): array
    {
        if (! in_array($sourceType, $this->backfill->supportedSourceTypes(), true)) {
            throw new RuntimeException('portfolio_liquidity_backfill_source_invalid');
        }

        $leaseToken = (string) Str::uuid();
        $claim = DB::transaction(function () use ($organizationId, $sourceType, $leaseToken): array {
            PortfolioLiquidityBackfillCheckpoint::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
            ]);
            $checkpoint = PortfolioLiquidityBackfillCheckpoint::query()
                ->where('organization_id', $organizationId)
                ->where('source_type', $sourceType)
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();
            if ($checkpoint->lease_token !== null
                && $checkpoint->lease_expires_at !== null
                && $checkpoint->lease_expires_at->isAfter($now)) {
                throw new RuntimeException('portfolio_liquidity_backfill_lease_busy');
            }
            $ingestionStartedAt = $checkpoint->ingestion_started_at ?? $now;
            $checkpoint->forceFill([
                'status' => 'running',
                'lease_token' => $leaseToken,
                'lease_expires_at' => $now->clone()->addSeconds(self::LEASE_SECONDS),
                'ingestion_started_at' => $ingestionStartedAt,
                'failure_code' => null,
            ])->save();

            return [
                'cursor' => (int) $checkpoint->source_cursor,
                'ingestion_started_at' => DateTimeImmutable::createFromInterface($ingestionStartedAt),
            ];
        });

        try {
            $result = $this->backfill->projectSourceSlice(
                $sourceType,
                $organizationId,
                $claim['cursor'],
                $limit,
                $claim['ingestion_started_at'],
            );
            DB::transaction(function () use ($organizationId, $sourceType, $leaseToken, $result): void {
                $checkpoint = PortfolioLiquidityBackfillCheckpoint::query()
                    ->where('organization_id', $organizationId)
                    ->where('source_type', $sourceType)
                    ->where('lease_token', $leaseToken)
                    ->lockForUpdate()
                    ->first();
                if (! $checkpoint instanceof PortfolioLiquidityBackfillCheckpoint) {
                    throw new RuntimeException('portfolio_liquidity_backfill_lease_lost');
                }
                $complete = $result['has_more'] === false;
                $checkpoint->forceFill([
                    'source_cursor' => (int) ($result['next_cursor'] ?? $checkpoint->source_cursor),
                    'status' => $complete ? 'completed' : 'pending',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => $complete ? now() : null,
                ])->save();
            });

            return $result;
        } catch (Throwable $exception) {
            PortfolioLiquidityBackfillCheckpoint::query()
                ->where('organization_id', $organizationId)
                ->where('source_type', $sourceType)
                ->where('lease_token', $leaseToken)
                ->update([
                    'status' => 'failed',
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'failure_code' => substr($exception::class, 0, 255),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }
}
