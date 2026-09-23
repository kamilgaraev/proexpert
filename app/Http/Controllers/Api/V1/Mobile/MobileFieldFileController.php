<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\MobileFieldFileIndexRequest;
use App\Http\Requests\Api\V1\Mobile\MobileFieldFileShowRequest;
use App\Http\Requests\Api\V1\Mobile\MobileFieldFileUploadRequest;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileFieldFilesService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MobileFieldFileController extends Controller
{
    public function __construct(private readonly MobileFieldFilesService $files) {}

    public function index(MobileFieldFileIndexRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $result = $this->files->paginate($user, $organizationId, $request->validated());

            return MobileResponse::paginated($result['data'], $result['meta']);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'files.index');
        }
    }

    public function show(MobileFieldFileShowRequest $request, int $fileId): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);

            return MobileResponse::success([
                'item' => $this->files->show($user, $organizationId, $fileId, (int) $request->validated('project_id')),
            ]);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'files.show');
        }
    }

    public function store(MobileFieldFileUploadRequest $request): JsonResponse
    {
        try {
            [$user, $organizationId] = $this->context($request);
            $item = $this->files->upload($user, $organizationId, $request->validated());

            return MobileResponse::success(['item' => $item], null, 201);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('mobile_companions.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 403);
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('mobile_companions.errors.item_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'files.store');
        }
    }

    /** @return array{User, int} */
    private function context(MobileFieldFileIndexRequest|MobileFieldFileShowRequest|MobileFieldFileUploadRequest $request): array
    {
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');
        if (! $user instanceof User || $organizationId <= 0) {
            throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
        }

        return [$user, $organizationId];
    }

    private function failed(MobileFieldFileIndexRequest|MobileFieldFileShowRequest|MobileFieldFileUploadRequest $request, Throwable $exception, string $action): JsonResponse
    {
        Log::error('mobile.field_files.'.$action.'.failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('mobile_companions.errors.action_failed'), 500);
    }
}
