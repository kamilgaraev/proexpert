<?php

namespace App\Services\Landing;

use App\Models\OrganizationModuleActivation;
use App\Models\OrganizationBalance;
use App\Models\BalanceTransaction;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;

class ModuleCancellationService
{
    public function cancelModule(int $organizationId, string $moduleSlug): array
    {
        return DB::transaction(function () use ($organizationId, $moduleSlug) {
            
            $activation = OrganizationModuleActivation::with('module')
                ->whereHas('module', function ($query) use ($moduleSlug) {
                    $query->where('slug', $moduleSlug);
                })
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->first();

            if (!$activation) {
                throw new BusinessLogicException("Активный модуль '{$moduleSlug}' не найден для организации");
            }

            if ($activation->module->price == 0) {
                // Бесплатный модуль - просто деактивируем
                $activation->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'user_request'
                ]);

                return [
                    'success' => true,
                    'refund_amount' => 0,
                    'message' => 'Бесплатный модуль успешно отключен'
                ];
            }

            // Расчет возврата для платного модуля
            $refundData = $this->calculateRefund($activation);
            
            if ($refundData['refund_amount'] > 0) {
                $this->processRefund($organizationId, $activation, $refundData);
            }

            // Деактивируем модуль
            $activation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'user_request',
                'refund_amount' => $refundData['refund_amount'],
                'refund_details' => [
                    'days_used' => $refundData['days_used'],
                    'days_total' => $refundData['days_total'],
                    'days_remaining' => $refundData['days_remaining'],
                    'daily_cost' => $refundData['daily_cost']
                ]
            ]);

            Log::info('Module cancelled with refund', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'refund_amount' => $refundData['refund_amount'],
                'days_used' => $refundData['days_used'],
                'days_remaining' => $refundData['days_remaining']
            ]);

            return [
                'success' => true,
                'refund_amount' => $refundData['refund_amount'],
                'days_used' => $refundData['days_used'],
                'days_remaining' => $refundData['days_remaining'],
                'message' => $refundData['refund_amount'] > 0 
                    ? "Модуль отключен. Возвращено {$refundData['refund_amount']} ₽ на баланс" 
                    : 'Модуль отключен'
            ];
        });
    }

    private function calculateRefund(OrganizationModuleActivation $activation): array
    {
        $activatedAt = Carbon::parse($activation->activated_at);
        $currentDate = now();
        
        // Если активация была сегодня или вчера - полный возврат
        $daysUsed = $activatedAt->diffInDays($currentDate);
        if ($daysUsed <= 1) {
            $daysUsed = 0;
        }

        // Считаем что месяц = 30 дней для простоты расчета
        $daysInMonth = 30;
        $dailyCost = round($activation->module->price / $daysInMonth, 2);
        $daysRemaining = max(0, $daysInMonth - $daysUsed);
        
        $refundAmount = round($daysRemaining * $dailyCost, 2);

        return [
            'days_used' => $daysUsed,
            'days_total' => $daysInMonth,
            'days_remaining' => $daysRemaining,
            'daily_cost' => $dailyCost,
            'refund_amount' => $refundAmount
        ];
    }

    private function processRefund(int $organizationId, OrganizationModuleActivation $activation, array $refundData): void
    {
        // Получаем или создаем баланс организации
        $balance = OrganizationBalance::firstOrCreate(
            ['organization_id' => $organizationId],
            ['balance' => 0, 'currency' => 'RUB']
        );

        // Увеличиваем баланс
        $balance->increment('balance', $refundData['refund_amount']);

        // Создаем транзакцию возврата
        BalanceTransaction::create([
            'organization_id' => $organizationId,
            'type' => 'refund',
            'amount' => $refundData['refund_amount'],
            'description' => "Возврат за отмену модуля '{$activation->module->name}'",
            'balance_after' => $balance->fresh()->balance,
            'reference_type' => 'module_cancellation',
            'reference_id' => $activation->id,
            'metadata' => [
                'module_slug' => $activation->module->slug,
                'days_used' => $refundData['days_used'],
                'days_remaining' => $refundData['days_remaining'],
                'daily_cost' => $refundData['daily_cost']
            ]
        ]);
    }

    public function getModuleCancellationPreview(int $organizationId, string $moduleSlug): array
    {
        $activation = OrganizationModuleActivation::with('module')
            ->whereHas('module', function ($query) use ($moduleSlug) {
                $query->where('slug', $moduleSlug);
            })
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();

        if (!$activation) {
            throw new BusinessLogicException("Активный модуль '{$moduleSlug}' не найден");
        }

        if ($activation->module->price == 0) {
            return [
                'can_cancel' => true,
                'refund_amount' => 0,
                'message' => 'Бесплатный модуль можно отключить без возврата средств'
            ];
        }

        $refundData = $this->calculateRefund($activation);

        return [
            'can_cancel' => true,
            'refund_amount' => $refundData['refund_amount'],
            'days_used' => $refundData['days_used'],
            'days_remaining' => $refundData['days_remaining'],
            'daily_cost' => $refundData['daily_cost'],
            'message' => $refundData['refund_amount'] > 0 
                ? "При отмене будет возвращено {$refundData['refund_amount']} ₽ за {$refundData['days_remaining']} неиспользованных дней"
                : 'Возврат не предусмотрен (модуль использовался весь период)'
        ];
    }
}
