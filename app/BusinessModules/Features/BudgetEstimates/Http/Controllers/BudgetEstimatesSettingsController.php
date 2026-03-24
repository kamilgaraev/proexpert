<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\UpdateBudgetEstimatesSettingsRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class BudgetEstimatesSettingsController extends Controller
{
    public function __construct(
        private readonly BudgetEstimatesModule $module
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            return AdminResponse::success($this->module->getSettings($organizationId));
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.show.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.settings.load_error'), 500);
        }
    }

    public function update(UpdateBudgetEstimatesSettingsRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $this->module->applySettings($organizationId, $request->validated());

            return AdminResponse::success(
                $this->module->getSettings($organizationId),
                trans_message('budget_estimates.settings.updated')
            );
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.update.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.settings.update_error'), 500);
        }
    }

    public function reset(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $defaultSettings = $this->module->getDefaultSettings();

            $this->module->applySettings($organizationId, $defaultSettings);

            return AdminResponse::success($defaultSettings, trans_message('budget_estimates.settings.reset'));
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.reset.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.settings.reset_error'), 500);
        }
    }

    public function info(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success([
                'name' => $this->module->getName(),
                'slug' => $this->module->getSlug(),
                'version' => $this->module->getVersion(),
                'description' => $this->module->getDescription(),
                'type' => $this->module->getType()->value,
                'billing_model' => $this->module->getBillingModel()->value,
                'features' => $this->module->getFeatures(),
                'permissions' => $this->module->getPermissions(),
                'limits' => $this->module->getLimits(),
            ]);
        } catch (\Exception $e) {
            Log::error('budget_estimates.settings.info.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('budget_estimates.info_load_error'), 500);
        }
    }
}
