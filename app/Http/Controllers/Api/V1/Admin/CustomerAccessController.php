<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\Customer\CustomerTeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class CustomerAccessController extends Controller
{
    public function __construct(
        private readonly CustomerTeamAccessService $customerTeamAccessService
    ) {
    }

    public function show(Request $request, User $user): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->customerTeamAccessService->getUserAccess($user, $request)
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('admin.customer_access.show.failed', [
                'user_id' => $request->user()?->id,
                'target_user_id' => $user->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('errors.customer_access.load_failed'), 500);
        }
    }

    public function update(Request $request, User $user): JsonResponse
    {
        try {
            $validated = $request->validate([
                'role_slug' => ['nullable', 'string', 'max:120'],
                'project_ids' => ['nullable', 'array'],
                'project_ids.*' => ['integer', 'exists:projects,id'],
                'is_active' => ['required', 'boolean'],
            ]);

            return AdminResponse::success(
                $this->customerTeamAccessService->updateUserAccess($user, $request, $validated)
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('errors.customer_access.invalid_params'), 422, $exception->errors());
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('admin.customer_access.update.failed', [
                'user_id' => $request->user()?->id,
                'target_user_id' => $user->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('errors.customer_access.update_failed'), 500);
        }
    }
}
