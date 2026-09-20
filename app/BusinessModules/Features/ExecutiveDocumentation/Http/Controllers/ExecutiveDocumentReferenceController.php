<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\ExecutiveDocumentReferenceRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentReferenceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Exceptions\BusinessLogicException;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentReferenceController extends Controller
{
    public function __construct(private readonly ExecutiveDocumentReferenceService $service)
    {
    }

    public function index(ExecutiveDocumentReferenceRequest $request): JsonResponse
    {
        try {
            $paginator = $this->service->paginate(
                (int) $request->attributes->get('current_organization_id'),
                $request->validated(),
                (int) $request->user()?->id,
            );

            return AdminResponse::paginated(
                $paginator->items(),
                [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), in_array($e->getCode(), [403, 404], true) ? $e->getCode() : 422);
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);

            return AdminResponse::error(trans_message('executive_documentation.errors.references_failed'), 500);
        }
    }
}
