<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileCompanionModulesService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MobileCompanionModuleController extends Controller
{
    public function __construct(
        private readonly MobileCompanionModulesService $service
    ) {
    }

    public function index(Request $request, string $module): JsonResponse
    {
        try {
            $user = $this->mobileUser($request);
            $organizationId = $this->organizationId($request);
            $this->ensureCanView($module, $user, $organizationId);
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'status' => ['nullable', 'string', 'max:80'],
                'q' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            return MobileResponse::success(
                $this->service->list(
                    $module,
                    $user,
                    $organizationId,
                    $validated,
                    min((int) $request->input('per_page', 20), 50)
                )
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return $this->domainFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $module, 'index');
        }
    }

    public function show(Request $request, string $module, int $id): JsonResponse
    {
        try {
            $user = $this->mobileUser($request);
            $organizationId = $this->organizationId($request);
            $this->ensureCanView($module, $user, $organizationId);

            return MobileResponse::success(
                $this->service->detail($module, $id, $user, $organizationId)
            );
        } catch (DomainException $exception) {
            return $this->domainFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $module, 'show');
        }
    }

    public function action(Request $request, string $module, int $id, string $action): JsonResponse
    {
        try {
            $user = $this->mobileUser($request);
            $organizationId = $this->organizationId($request);
            $this->ensureCanView($module, $user, $organizationId);
            $validated = $this->validated($request, [
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return MobileResponse::success(
                $this->service->executeAction($module, $id, $action, $user, $organizationId, $validated),
                trans_message('mobile_companions.messages.action_completed')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return $this->domainFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $module, 'action');
        }
    }

    private function ensureCanView(string $module, User $user, int $organizationId): void
    {
        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_companions.errors.no_organization'));
        }

        if ($this->service->canView($module, $user, $organizationId)) {
            return;
        }

        throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
    }

    private function mobileUser(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        return $user;
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, [
            'project_id.integer' => trans_message('mobile_companions.validation.project_invalid'),
            'status.max' => trans_message('mobile_companions.validation.status_invalid'),
            'q.max' => trans_message('mobile_companions.validation.search_too_long'),
            'per_page.max' => trans_message('mobile_companions.validation.per_page_max'),
            'comment.max' => trans_message('mobile_companions.validation.comment_too_long'),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationFailed(ValidationException $exception): JsonResponse
    {
        return MobileResponse::error(
            trans_message('mobile_companions.errors.validation_failed'),
            422,
            $exception->errors()
        );
    }

    private function domainFailed(DomainException $exception): JsonResponse
    {
        $message = $exception->getMessage();

        if ($message === trans_message('mobile_companions.errors.permission_denied')) {
            return MobileResponse::error($message, 403, null, ['error_code' => 'PERMISSION_DENIED']);
        }

        if (
            $message === trans_message('mobile_companions.errors.module_not_found')
            || $message === trans_message('mobile_companions.errors.item_not_found')
        ) {
            return MobileResponse::error($message, 404);
        }

        return MobileResponse::error($message, 422);
    }

    private function failed(Request $request, \Throwable $exception, string $module, string $action): JsonResponse
    {
        Log::error('mobile_companions.failed', [
            'module' => $module,
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('mobile_companions.errors.action_failed'), 500);
    }
}
