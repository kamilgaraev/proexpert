<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Enums\Billing\CommercialOfferType;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankPaymentProof;
use App\Models\OrganizationCommercialAccount;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class BankPaymentProofService
{
    public function submit(User $actor, int $organizationId, string $invoiceId, UploadedFile $file): CommercialBankPaymentProof
    {
        app(BankBillingAccess::class)->organization($actor, $organizationId, true);
        $mime = $file->getMimeType();
        if (! $file->isValid() || ! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)
            || $file->getSize() < 1 || $file->getSize() > 5 * 1024 * 1024) {
            throw new InvalidArgumentException(trans_message('bank_billing.proof_file_invalid'));
        }
        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents) || strlen($contents) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException(trans_message('bank_billing.proof_file_invalid'));
        }
        $hash = hash('sha256', $contents);
        $path = null;
        try {
            $proof = DB::transaction(function () use ($actor, $organizationId, $invoiceId, $mime, $contents, $hash, &$path): CommercialBankPaymentProof {
                $account = OrganizationCommercialAccount::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
                $invoice = CommercialBankInvoice::query()->where('organization_id', $organizationId)
                    ->where('public_id', $invoiceId)->lockForUpdate()->firstOrFail();
                $existing = $invoice->paymentProof;
                if ($existing !== null) {
                    if (! hash_equals($existing->sha256, $hash)) {
                        throw new InvalidArgumentException(trans_message('bank_billing.proof_already_submitted'));
                    }

                    return $existing;
                }
                if (! $this->canSubmit($invoice) || (int) $invoice->order->commercial_account_id !== (int) $account->id
                    || CommercialBankPaymentProof::query()->where('organization_id', $organizationId)->where('sha256', $hash)->exists()) {
                    throw new InvalidArgumentException(trans_message('bank_billing.proof_unavailable'));
                }
                $localNow = CarbonImmutable::now('Europe/Moscow');
                $now = $localNow->utc();
                $deadline = $localNow->addWeekdays(3)->utc();
                $order = $invoice->order;
                if ($order->kind === 'purchase' && $order->current_package_slugs !== [] && $order->period_end_at !== null) {
                    $deadline = $deadline->min($order->period_end_at);
                }
                if ($deadline->lessThanOrEqualTo($now)) {
                    throw new InvalidArgumentException(trans_message('bank_billing.proof_unavailable'));
                }
                $id = (string) Str::uuid();
                $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime];
                $path = 'org-'.$organizationId.'/billing/payment-proofs/'.$id.'.'.$extension;
                app(FileService::class)->putPrivate($path, $contents, $mime, $hash);
                $packages = $order->offer_type === CommercialOfferType::FullSuite
                    ? array_column(app(PackageCatalogService::class)->allPackages(), 'slug') : $order->selected_package_slugs;

                return CommercialBankPaymentProof::query()->create([
                    'public_id' => $id, 'organization_id' => $organizationId, 'commercial_account_id' => $account->id,
                    'commercial_bank_invoice_id' => $invoice->id, 'submitted_by' => $actor->id, 'status' => 'pending',
                    'storage_path' => $path, 'mime' => $mime, 'size_bytes' => strlen($contents), 'sha256' => $hash,
                    'package_slugs' => $packages, 'resource_addons' => $order->selected_resource_addons ?? [],
                    'submitted_at' => $now, 'temporary_expires_at' => $deadline,
                ]);
            }, 1);
        } catch (Throwable $exception) {
            if ($path !== null) {
                try {
                    app(FileService::class)->deleteCurrent($path);
                } catch (Throwable $cleanup) {
                    Log::error('Bank payment proof cleanup failed.', ['organization_id' => $organizationId, 'exception' => $cleanup::class]);
                }
            }
            throw $exception;
        }
        app(BankPaymentProofAccess::class)->invalidate($organizationId);

        return $proof;
    }

    public function canSubmit(CommercialBankInvoice $invoice, ?bool $historyRestricted = null): bool
    {
        return $invoice->paid_at === null && in_array($invoice->status, ['awaiting_payment', 'partially_paid'], true)
            && $invoice->expires_at->isFuture() && $invoice->paid_minor < $invoice->amount_minor
            && in_array($invoice->order->kind, ['purchase', 'renewal'], true) && $invoice->order->status->value === 'pending_payment'
            && ! ($historyRestricted ?? $this->historyRestricted((int) $invoice->organization_id));
    }

    public function historyRestricted(int $organizationId): bool
    {
        return CommercialBankPaymentProof::query()->where('organization_id', $organizationId)->where(function ($query): void {
            $query->whereIn('status', ['pending', 'review'])->orWhere('submitted_at', '>', now()->subDays(30));
        })->exists();
    }

    public function payload(?CommercialBankPaymentProof $proof, CommercialBankInvoice $invoice): ?array
    {
        if ($proof === null) {
            return null;
        }

        return ['id' => $proof->public_id, 'status' => $proof->status, 'submitted_at' => $proof->submitted_at->toJSON(),
            'temporary_expires_at' => $proof->temporary_expires_at->toJSON(),
            'temporary_access_active' => $proof->status === 'pending' && $proof->submitted_at->isPast() && $proof->temporary_expires_at->isFuture()
                && in_array($invoice->status, ['awaiting_payment', 'partially_paid'], true) && $invoice->paid_at === null
                && $invoice->order->status->value === 'pending_payment',
            'resolved_at' => $proof->resolved_at?->toJSON(), 'mime' => $proof->mime];
    }

    public function owned(User $actor, int $organizationId, string $publicId): CommercialBankPaymentProof
    {
        app(BankBillingAccess::class)->organization($actor, $organizationId);

        return CommercialBankPaymentProof::query()->where('organization_id', $organizationId)->where('public_id', $publicId)
            ->whereHas('invoice', fn ($query) => $query->where('organization_id', $organizationId))->firstOrFail();
    }

    public function download(User $actor, int $organizationId, string $publicId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->stream($this->owned($actor, $organizationId, $publicId));
    }

    private function stream(CommercialBankPaymentProof $proof): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $stream = app(FileService::class)->readCurrent($proof->storage_path);
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$proof->mime];

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'MOST-payment-'.$proof->public_id.'.'.$extension,
            ['Content-Type' => $proof->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function downloadForOperator(SystemAdmin $actor, CommercialBankPaymentProof $proof): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        app(BankBillingAccess::class)->operator($actor);

        return $this->stream($proof);
    }

    public function confirm(CommercialBankInvoice $invoice): void
    {
        if (! Schema::hasTable('commercial_bank_payment_proofs') || $invoice->paid_at === null || $invoice->paid_minor < $invoice->amount_minor) {
            return;
        }
        $this->resolve($invoice, 'confirmed');
    }

    public function cancel(CommercialBankInvoice $invoice): void
    {
        if (Schema::hasTable('commercial_bank_payment_proofs')) {
            $this->resolve($invoice, 'canceled');
        }
    }

    private function resolve(CommercialBankInvoice $invoice, string $status): void
    {
        if (CommercialBankPaymentProof::query()->where('commercial_bank_invoice_id', $invoice->id)
            ->whereIn('status', ['pending', 'review'])->update(['status' => $status, 'resolved_at' => now(), 'updated_at' => now()]) > 0) {
            DB::afterCommit(fn () => app(BankPaymentProofAccess::class)->invalidate((int) $invoice->organization_id));
        }
    }

    public function processExpired(): void
    {
        if (! Schema::hasTable('commercial_bank_payment_proofs')) {
            return;
        }
        CommercialBankPaymentProof::query()->where('status', 'pending')->where('temporary_expires_at', '<=', now())
            ->orderBy('id')->limit(100)->get()->each(function (CommercialBankPaymentProof $source): void {
                DB::transaction(function () use ($source): void {
                    OrganizationCommercialAccount::query()->whereKey($source->commercial_account_id)->lockForUpdate()->firstOrFail();
                    $invoice = CommercialBankInvoice::query()->whereKey($source->commercial_bank_invoice_id)->lockForUpdate()->firstOrFail();
                    $proof = CommercialBankPaymentProof::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
                    if ($proof->status !== 'pending' || $proof->temporary_expires_at->isFuture()) {
                        return;
                    }
                    $status = $invoice->paid_at !== null && $invoice->paid_minor >= $invoice->amount_minor ? 'confirmed'
                        : (in_array($invoice->status, ['canceled', 'refunded'], true) ? 'canceled' : 'review');
                    $proof->forceFill(['status' => $status, 'resolved_at' => $status === 'review' ? null : now()])->save();
                    DB::afterCommit(fn () => app(BankPaymentProofAccess::class)->invalidate((int) $proof->organization_id));
                }, 3);
            });
    }

    public function reject(SystemAdmin $actor, CommercialBankPaymentProof $source, string $reason): void
    {
        app(BankBillingAccess::class)->operator($actor);
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException(trans_message('bank_billing.review_reason_required'));
        }
        DB::transaction(function () use ($actor, $source, $reason): void {
            OrganizationCommercialAccount::query()->whereKey($source->commercial_account_id)->lockForUpdate()->firstOrFail();
            CommercialBankInvoice::query()->whereKey($source->commercial_bank_invoice_id)->lockForUpdate()->firstOrFail();
            $proof = CommercialBankPaymentProof::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! in_array($proof->status, ['pending', 'review'], true)) {
                throw new InvalidArgumentException(trans_message('bank_billing.invalid'));
            }
            $proof->forceFill(['status' => 'rejected', 'reviewed_by' => $actor->id, 'review_note' => trim($reason), 'resolved_at' => now()])->save();
            DB::afterCommit(fn () => app(BankPaymentProofAccess::class)->invalidate((int) $proof->organization_id));
        }, 3);
    }
}
