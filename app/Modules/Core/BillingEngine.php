<?php

namespace App\Modules\Core;

use App\Models\Organization;
use App\Models\Module;
use App\Modules\Contracts\BillableInterface;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Exceptions\Billing\InsufficientBalanceException;
use Illuminate\Support\Facades\Log;

class BillingEngine 
{
    protected BalanceServiceInterface $balanceService;
    
    public function __construct(BalanceServiceInterface $balanceService)
    {
        $this->balanceService = $balanceService;
    }
    
    public function chargeForModule(Organization $organization, Module $module, ?BillableInterface $billableModule = null): bool
    {
        if ($module->billing_model === 'free') {
            return true;
        }
        
        $amount = $this->calculateChargeAmount($module, $billableModule);
        
        if ($amount <= 0) {
            return true;
        }
        
        try {
            // Конвертируем в копейки для BalanceService
            $amountCents = (int) round($amount * 100);
            
            $this->balanceService->debitBalance(
                $organization,
                $amountCents,
                "Активация модуля '{$module->name}'"
            );
            
            return true;
        } catch (InsufficientBalanceException $e) {
            Log::warning("Insufficient balance for module activation", [
                'organization_id' => $organization->id,
                'module_slug' => $module->slug,
                'required_amount' => $amount
            ]);
            
            return false;
        }
    }
    
    public function refundModule(Organization $organization, Module $module, float $amount, string $reason = ''): bool
    {
        if ($amount <= 0) {
            return true;
        }
        
        try {
            // Конвертируем в копейки
            $amountCents = (int) round($amount * 100);
            
            $this->balanceService->creditBalance(
                $organization,
                $amountCents,
                $reason ?: "Возврат за модуль '{$module->name}'"
            );
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to refund module", [
                'organization_id' => $organization->id,
                'module_slug' => $module->slug,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function canAfford(Organization $organization, Module $module, ?BillableInterface $billableModule = null): bool
    {
        if ($module->billing_model === 'free') {
            return true;
        }
        
        $amount = $this->calculateChargeAmount($module, $billableModule);
        
        if ($amount <= 0) {
            return true;
        }
        
        $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
        $currentBalance = $balance->balance / 100; // Конвертируем из копеек в рубли
        
        return $currentBalance >= $amount;
    }
    
    public function getBalance(Organization $organization): float
    {
        $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
        return $balance->balance / 100; // Конвертируем из копеек в рубли
    }
    
    public function calculateChargeAmount(Module $module, ?BillableInterface $billableModule = null): float
    {
        if ($billableModule) {
            return $billableModule->getPrice();
        }
        
        $pricingConfig = $module->pricing_config ?? [];
        return (float) ($pricingConfig['base_price'] ?? 0);
    }
    
    public function calculateRefundAmount(Module $module, \DateTime $activatedAt, ?\DateTime $expiresAt = null): float
    {
        if (!$expiresAt || $module->billing_model !== 'subscription') {
            return 0;
        }
        
        $now = new \DateTime();
        $totalDays = $activatedAt->diff($expiresAt)->days;
        $remainingDays = max(0, $now->diff($expiresAt)->days);
        
        if ($totalDays <= 0 || $remainingDays <= 0) {
            return 0;
        }
        
        $pricingConfig = $module->pricing_config ?? [];
        $totalPrice = (float) ($pricingConfig['base_price'] ?? 0);
        
        // Пропорциональный возврат
        return ($remainingDays / $totalDays) * $totalPrice;
    }
}
