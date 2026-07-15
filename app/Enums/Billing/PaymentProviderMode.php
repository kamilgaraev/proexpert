<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Exceptions\Billing\PaymentGatewayConfigurationException;

use function trans_message;

enum PaymentProviderMode: string
{
    case Mock = 'mock';
    case YooKassaTest = 'yookassa_test';
    case YooKassaLive = 'yookassa_live';

    public static function configured(): self
    {
        $mode = self::tryFrom(trim((string) config('services.yookassa.mode')));

        if ($mode === null || ($mode === self::Mock && app()->environment('production'))) {
            throw new PaymentGatewayConfigurationException(
                trans_message('billing.provider.configuration_error'),
            );
        }

        return $mode;
    }

    public function testMode(): bool
    {
        return $this !== self::YooKassaLive;
    }
}
