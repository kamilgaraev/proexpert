<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CommercialRefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

final class CreateCommercialRefundCommand extends Command
{
    protected $signature = 'commercial:refund
        {order : Публичный идентификатор заказа}
        {amount : Сумма в формате 100.00 или full}
        {reason : Основание возврата}
        {idempotency-key : Уникальный ключ операции до 64 символов}
        {--currency=RUB : Валюта возврата}
        {--confirm : Обязательное явное подтверждение выполнения}';

    protected $description = 'Создать полный или частичный возврат коммерческого платежа';

    public function handle(CommercialRefundService $service): int
    {
        if ($this->option('confirm') !== true) {
            $this->error(trans_message('billing.refund.confirm_required'));

            return self::INVALID;
        }

        $amount = $this->amountMinor((string) $this->argument('amount'));
        if ($amount === false) {
            $this->error(trans_message('billing.refund.invalid'));

            return self::INVALID;
        }

        $orderId = trim((string) $this->argument('order'));
        try {
            $refund = $service->create(
                $orderId,
                $amount,
                (string) $this->option('currency'),
                (string) $this->argument('reason'),
                (string) $this->argument('idempotency-key'),
            );
        } catch (Throwable $exception) {
            Log::error('Commercial refund operation failed.', [
                'order_public_id' => $orderId,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);
            $this->error(trans_message('billing.refund.failed'));

            return self::FAILURE;
        }

        Log::info('Commercial refund operation submitted.', [
            'order_public_id' => $orderId,
            'refund_id' => $refund->id,
            'provider_refund_id' => $refund->provider_refund_id,
            'amount_minor' => $refund->amount_minor,
            'currency' => $refund->currency,
        ]);
        $this->info(trans_message('billing.refund.created'));

        return self::SUCCESS;
    }

    private function amountMinor(string $amount): int|false|null
    {
        $amount = trim($amount);
        if ($amount === 'full') {
            return null;
        }
        if (preg_match('/^(0|[1-9]\d*)\.(\d{2})$/D', $amount, $matches) !== 1) {
            return false;
        }
        $minor = ((int) $matches[1] * 100) + (int) $matches[2];

        return $minor > 0 ? $minor : false;
    }
}
