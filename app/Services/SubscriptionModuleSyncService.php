<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\OrganizationModuleActivation;
use App\Models\Module;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionModuleSyncService
{
    public function syncModulesOnSubscribe(OrganizationSubscription $subscription): array
    {
        $plan = $subscription->plan;
        $organizationId = $subscription->organization_id;
        
        $modulesToActivate = Module::active()
            ->includedInPlan($plan->slug)
            ->get();
        
        if ($modulesToActivate->isEmpty()) {
            return [
                'success' => true,
                'activated_count' => 0,
                'converted_count' => 0,
                'modules' => []
            ];
        }
        
        $activatedCount = 0;
        $convertedCount = 0;
        $modules = [];
        
        DB::transaction(function () use (
            $modulesToActivate, 
            $organizationId, 
            $subscription, 
            &$activatedCount, 
            &$convertedCount, 
            &$modules
        ) {
            foreach ($modulesToActivate as $module) {
                $existingActivation = OrganizationModuleActivation::where('organization_id', $organizationId)
                    ->where('module_id', $module->id)
                    ->first();
                
                if ($existingActivation) {
                    if ($existingActivation->isStandalone() && $existingActivation->isActive()) {
                        $existingActivation->convertToBundled($subscription);
                        $convertedCount++;
                        $modules[] = [
                            'slug' => $module->slug,
                            'name' => $module->name,
                            'action' => 'converted'
                        ];
                        
                        Log::info('Module converted to bundled', [
                            'module_slug' => $module->slug,
                            'organization_id' => $organizationId,
                            'subscription_id' => $subscription->id
                        ]);
                    }
                } else {
                    OrganizationModuleActivation::create([
                        'organization_id' => $organizationId,
                        'module_id' => $module->id,
                        'subscription_id' => $subscription->id,
                        'is_bundled_with_plan' => true,
                        'status' => 'active',
                        'activated_at' => now(),
                        'expires_at' => $subscription->ends_at,
                        'next_billing_date' => $subscription->next_billing_at,
                        'paid_amount' => 0,
                        'module_settings' => [],
                    ]);
                    
                    $activatedCount++;
                    $modules[] = [
                        'slug' => $module->slug,
                        'name' => $module->name,
                        'action' => 'activated'
                    ];
                    
                    Log::info('Module activated as bundled', [
                        'module_slug' => $module->slug,
                        'organization_id' => $organizationId,
                        'subscription_id' => $subscription->id
                    ]);
                }
            }
        });
        
        return [
            'success' => true,
            'activated_count' => $activatedCount,
            'converted_count' => $convertedCount,
            'modules' => $modules
        ];
    }
    
    public function syncModulesOnPlanChange(
        OrganizationSubscription $subscription,
        SubscriptionPlan $oldPlan,
        SubscriptionPlan $newPlan
    ): array {
        $organizationId = $subscription->organization_id;
        
        $oldModules = Module::active()->includedInPlan($oldPlan->slug)->pluck('id');
        $newModules = Module::active()->includedInPlan($newPlan->slug)->pluck('id');
        
        $modulesToRemove = $oldModules->diff($newModules);
        $modulesToAdd = $newModules->diff($oldModules);
        
        $deactivatedCount = 0;
        $activatedCount = 0;
        $convertedCount = 0;
        
        DB::transaction(function () use (
            $modulesToRemove,
            $modulesToAdd,
            $organizationId,
            $subscription,
            $newPlan,
            &$deactivatedCount,
            &$activatedCount,
            &$convertedCount
        ) {
            if ($modulesToRemove->isNotEmpty()) {
                $deactivatedCount = OrganizationModuleActivation::where('organization_id', $organizationId)
                    ->whereIn('module_id', $modulesToRemove)
                    ->where('is_bundled_with_plan', true)
                    ->update([
                        'status' => 'suspended',
                        'cancelled_at' => now(),
                        'cancellation_reason' => "Модуль не включён в план '{$newPlan->name}'"
                    ]);
                
                Log::info('Modules deactivated on plan downgrade', [
                    'count' => $deactivatedCount,
                    'organization_id' => $organizationId,
                    'old_plan' => $newPlan->slug
                ]);
            }
            
            if ($modulesToAdd->isNotEmpty()) {
                foreach ($modulesToAdd as $moduleId) {
                    $module = Module::find($moduleId);
                    if (!$module) continue;
                    
                    $existingActivation = OrganizationModuleActivation::where('organization_id', $organizationId)
                        ->where('module_id', $moduleId)
                        ->first();
                    
                    if ($existingActivation) {
                        if ($existingActivation->isStandalone() && $existingActivation->isActive()) {
                            $existingActivation->convertToBundled($subscription);
                            $convertedCount++;
                        }
                    } else {
                        OrganizationModuleActivation::create([
                            'organization_id' => $organizationId,
                            'module_id' => $moduleId,
                            'subscription_id' => $subscription->id,
                            'is_bundled_with_plan' => true,
                            'status' => 'active',
                            'activated_at' => now(),
                            'expires_at' => $subscription->ends_at,
                            'next_billing_date' => $subscription->next_billing_at,
                            'paid_amount' => 0,
                            'module_settings' => [],
                        ]);
                        
                        $activatedCount++;
                    }
                }
            }
        });
        
        return [
            'success' => true,
            'deactivated_count' => $deactivatedCount,
            'activated_count' => $activatedCount,
            'converted_count' => $convertedCount
        ];
    }
    
    public function syncModulesOnRenew(OrganizationSubscription $subscription): int
    {
        $updatedCount = $subscription->syncModulesExpiration();
        
        Log::info('Bundled modules renewed with subscription', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $updatedCount,
            'new_expires_at' => $subscription->ends_at
        ]);
        
        return $updatedCount;
    }
    
    public function handleSubscriptionCancellation(OrganizationSubscription $subscription): int
    {
        $deactivatedCount = $subscription->deactivateBundledModules('Подписка отменена');
        
        Log::warning('Bundled modules deactivated due to subscription cancellation', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $deactivatedCount
        ]);
        
        return $deactivatedCount;
    }
    
    public function handleSubscriptionReactivation(OrganizationSubscription $subscription): int
    {
        $reactivatedCount = $subscription->reactivateBundledModules();
        
        Log::info('Bundled modules reactivated with subscription', [
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'modules_count' => $reactivatedCount
        ]);
        
        return $reactivatedCount;
    }
    
    public function getBundledModulesForPlan(string $planSlug): array
    {
        $modules = Module::active()
            ->includedInPlan($planSlug)
            ->get();
        
        return $modules->map(function ($module) {
            return [
                'id' => $module->id,
                'name' => $module->name,
                'slug' => $module->slug,
                'description' => $module->description,
                'category' => $module->category,
                'features' => $module->features,
                'icon' => $module->icon,
            ];
        })->toArray();
    }
}

