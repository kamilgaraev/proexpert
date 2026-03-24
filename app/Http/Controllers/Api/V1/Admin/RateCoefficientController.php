<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RateCoefficient\StoreRateCoefficientRequest;
use App\Http\Requests\Api\V1\Admin\RateCoefficient\UpdateRateCoefficientRequest;
use App\Http\Resources\Api\V1\Admin\RateCoefficient\RateCoefficientCollection;
use App\Http\Resources\Api\V1\Admin\RateCoefficient\RateCoefficientResource;
use App\Http\Responses\AdminResponse;
use App\Services\RateCoefficient\RateCoefficientService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RateCoefficientController extends Controller
{
    public function __construct(
        protected RateCoefficientService $coefficientService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 15);
            $coefficients = $this->coefficientService->getAllCoefficients($request, $perPage);
            $payload = (new RateCoefficientCollection($coefficients))->response()->getData(true);

            return AdminResponse::paginated(
                $payload['data'] ?? [],
                is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                null,
                Response::HTTP_OK,
                null,
                is_array($payload['links'] ?? null) ? $payload['links'] : null
            );
        } catch (\Throwable $e) {
            Log::error('Failed to load rate coefficients', [
                'error' => $e->getMessage(),
                'filters' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function store(StoreRateCoefficientRequest $request): JsonResponse
    {
        try {
            $coefficient = $this->coefficientService->createCoefficient($request->toDto(), $request);

            return AdminResponse::success(
                (new RateCoefficientResource($coefficient))->resolve(),
                trans_message('rate_coefficients.created'),
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            Log::warning('Failed to create rate coefficient', [
                'error' => $e->getMessage(),
                'payload' => $request->validated(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : trans_message('rate_coefficients.create_error'),
                $this->resolveStatusCode($e->getCode(), Response::HTTP_BAD_REQUEST)
            );
        } catch (\Throwable $e) {
            Log::error('Unexpected error while creating rate coefficient', [
                'error' => $e->getMessage(),
                'payload' => $request->validated(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.create_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $coefficient = $this->coefficientService->findCoefficientById($id, $request);

            if ($coefficient === null) {
                return AdminResponse::error(
                    trans_message('rate_coefficients.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success((new RateCoefficientResource($coefficient))->resolve());
        } catch (\Throwable $e) {
            Log::error('Failed to show rate coefficient', [
                'error' => $e->getMessage(),
                'rate_coefficient_id' => $id,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function update(UpdateRateCoefficientRequest $request, int $id): JsonResponse
    {
        try {
            $coefficient = $this->coefficientService->updateCoefficient($id, $request->toDto(), $request);

            return AdminResponse::success(
                (new RateCoefficientResource($coefficient))->resolve(),
                trans_message('rate_coefficients.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('rate_coefficients.not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (BusinessLogicException $e) {
            Log::warning('Failed to update rate coefficient', [
                'error' => $e->getMessage(),
                'rate_coefficient_id' => $id,
                'payload' => $request->validated(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : trans_message('rate_coefficients.update_error'),
                $this->resolveStatusCode($e->getCode(), Response::HTTP_BAD_REQUEST)
            );
        } catch (\Throwable $e) {
            Log::error('Unexpected error while updating rate coefficient', [
                'error' => $e->getMessage(),
                'rate_coefficient_id' => $id,
                'payload' => $request->validated(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $deleted = $this->coefficientService->deleteCoefficient($id, $request);

            if (!$deleted) {
                return AdminResponse::error(
                    trans_message('rate_coefficients.delete_error'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return AdminResponse::success(null, trans_message('rate_coefficients.deleted'));
        } catch (BusinessLogicException $e) {
            Log::warning('Failed to delete rate coefficient', [
                'error' => $e->getMessage(),
                'rate_coefficient_id' => $id,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : trans_message('rate_coefficients.delete_error'),
                $this->resolveStatusCode($e->getCode(), Response::HTTP_NOT_FOUND)
            );
        } catch (\Throwable $e) {
            Log::error('Unexpected error while deleting rate coefficient', [
                'error' => $e->getMessage(),
                'rate_coefficient_id' => $id,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('rate_coefficients.delete_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function resolveStatusCode(int $statusCode, int $defaultStatusCode): int
    {
        return $statusCode >= 400 && $statusCode < 600
            ? $statusCode
            : $defaultStatusCode;
    }
}
