<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankPaymentProof;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class BankPaymentProofAccess
{
    public function grants(array $organizationIds): Collection
    {
        if ($organizationIds === [] || ! Schema::hasTable('commercial_bank_payment_proofs')) {
            return collect();
        }

        return CommercialBankPaymentProof::query()->whereIn('organization_id', $organizationIds)->temporary()->get();
    }

    public function packageSlugs(int $organizationId): array
    {
        return $this->grants([$organizationId])->flatMap(fn ($proof) => $proof->package_slugs)->unique()->values()->all();
    }

    public function cacheDiscriminator(int $organizationId): string
    {
        $context = Cache::remember('bank_proof_access_'.$organizationId, 300, static function () use ($organizationId): array {
            if (! Schema::hasTable('commercial_bank_payment_proofs')) {
                return [];
            }
            $proof = CommercialBankPaymentProof::query()->where('organization_id', $organizationId)->latest('id')
                ->first(['public_id', 'status', 'temporary_expires_at']);

            return $proof === null ? [] : [$proof->public_id, $proof->status, $proof->temporary_expires_at->getTimestamp()];
        });

        return $context === [] ? 'none' : implode('_', $context).'_'.((int) $context[2] > now()->getTimestamp() ? 'before' : 'after');
    }

    public function invalidate(int $organizationId): void
    {
        Cache::forget('bank_proof_access_'.$organizationId);
        app(\App\Modules\Core\AccessController::class)->clearAccessCache($organizationId);
    }
}
