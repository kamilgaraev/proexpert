<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseRequestNumberGenerator
{
    private const DEFAULT_PREFIX = 'ЗЗ';
    private const MATERIAL_PREFIX = 'ЗМ';
    private const EQUIPMENT_PREFIX = 'ЗТ';
    private const PERSONNEL_PREFIX = 'ЗК';

    public function generate(int $organizationId, ?SiteRequestTypeEnum $siteRequestType = null): string
    {
        $year = (int) date('Y');
        $month = (int) date('m');
        $prefix = sprintf('%s-%d%02d-', $this->prefixForSiteRequestType($siteRequestType), $year, $month);
        $prefixPattern = $prefix . '%';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $newNumber = DB::transaction(function () use ($organizationId, $year, $month, $prefix, $prefixPattern): int {
                    $counter = DB::table('purchase_request_number_counters')
                        ->where('organization_id', $organizationId)
                        ->where('year', $year)
                        ->where('month', $month)
                        ->lockForUpdate()
                        ->first();

                    $existingMax = $this->maxExistingNumber($organizationId, $prefix, $prefixPattern);
                    $newNumber = max(((int) ($counter->last_number ?? 0)) + 1, $existingMax + 1);
                    $now = now();

                    if ($counter) {
                        DB::table('purchase_request_number_counters')
                            ->where('id', $counter->id)
                            ->update([
                                'last_number' => $newNumber,
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('purchase_request_number_counters')->insert([
                            'organization_id' => $organizationId,
                            'year' => $year,
                            'month' => $month,
                            'last_number' => $newNumber,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    return $newNumber;
                });

                $requestNumber = sprintf('%s%04d', $prefix, $newNumber);

                Log::debug('procurement.purchase_request.number_generated', [
                    'organization_id' => $organizationId,
                    'year' => $year,
                    'month' => $month,
                    'generated_number' => $requestNumber,
                    'counter_value' => $newNumber,
                ]);

                return $requestNumber;
            } catch (QueryException $exception) {
                if ($attempt === 3) {
                    $this->logGenerationFailure($organizationId, $exception);
                    throw $exception;
                }
            } catch (\Exception $exception) {
                $this->logGenerationFailure($organizationId, $exception);
                throw $exception;
            }
        }

        throw new \RuntimeException('Unable to generate purchase request number');
    }

    public function prefixForSiteRequestType(?SiteRequestTypeEnum $siteRequestType): string
    {
        return match ($siteRequestType) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => self::MATERIAL_PREFIX,
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => self::EQUIPMENT_PREFIX,
            SiteRequestTypeEnum::PERSONNEL_REQUEST => self::PERSONNEL_PREFIX,
            default => self::DEFAULT_PREFIX,
        };
    }

    private function maxExistingNumber(int $organizationId, string $prefix, string $prefixPattern): int
    {
        return DB::table('purchase_requests')
            ->where('organization_id', $organizationId)
            ->where('request_number', 'like', $prefixPattern)
            ->pluck('request_number')
            ->map(static function (string $requestNumber) use ($prefix): int {
                $suffix = substr($requestNumber, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }

    private function logGenerationFailure(int $organizationId, \Throwable $exception): void
    {
        Log::error('procurement.purchase_request.number_generation_failed', [
            'organization_id' => $organizationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
