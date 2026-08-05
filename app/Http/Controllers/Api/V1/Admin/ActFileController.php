<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\File\ListFilesRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Organization\OrganizationContext;
use App\Services\Storage\PersonalFileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function response;
use function trans_message;

final class ActFileController extends Controller
{
    private const DIRECTORY = 'acts';

    public function __construct(private readonly PersonalFileService $personalFiles) {}

    public function index(ListFilesRequest $request): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $paginator = $this->personalFiles->paginate(
                $organizationId,
                $userId,
                $request->validated(),
                self::DIRECTORY,
            );
            $paginator->getCollection()->transform(
                fn ($file): array => $this->personalFiles->payload($file),
            );

            return AdminResponse::paginated(
                $paginator->items(),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                trans_message('files.files_loaded'),
            );
        } catch (Throwable $exception) {
            $this->logFailure('act_files.index_failed', $exception, $request);

            return AdminResponse::error(trans_message('files.load_failed'), 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $file = $this->personalFiles->findOwned(
                $id,
                $organizationId,
                $userId,
                self::DIRECTORY,
            );

            return AdminResponse::success(
                $this->personalFiles->payload($file),
                trans_message('files.file_found'),
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (Throwable $exception) {
            $this->logFailure('act_files.show_failed', $exception, $request, $id);

            return AdminResponse::error(trans_message('files.operation_failed'), 500);
        }
    }

    public function download(Request $request, string $id): JsonResponse|StreamedResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $file = $this->personalFiles->findOwned(
                $id,
                $organizationId,
                $userId,
                self::DIRECTORY,
            );
            $stream = $this->personalFiles->read($file);

            return response()->streamDownload(
                static function () use ($stream): void {
                    fpassthru($stream);
                    fclose($stream);
                },
                (string) $file->original_name,
                ['Content-Type' => $file->mime_type ?: 'application/octet-stream'],
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (Throwable $exception) {
            $this->logFailure('act_files.download_failed', $exception, $request, $id);

            return AdminResponse::error(trans_message('files.operation_failed'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $this->personalFiles->delete($id, $organizationId, $userId, self::DIRECTORY);

            return AdminResponse::success(null, trans_message('files.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (Throwable $exception) {
            $this->logFailure('act_files.delete_failed', $exception, $request, $id);

            return AdminResponse::error(trans_message('files.delete_failed'), 500);
        }
    }

    /** @return array{0: int, 1: int} */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $organization = OrganizationContext::getOrganization() ?? $user?->currentOrganization;
        $organizationId = (int) ($organization?->id ?? 0);
        $userId = (int) ($user?->id ?? 0);
        if ($organizationId < 1 || $userId < 1) {
            throw new RuntimeException('personal_file_scope_missing');
        }

        return [$organizationId, $userId];
    }

    private function logFailure(
        string $event,
        Throwable $exception,
        Request $request,
        ?string $fileId = null,
    ): void {
        Log::error($event, [
            'exception_class' => $exception::class,
            'file_id' => $fileId,
            'user_id' => $request->user()?->id,
        ]);
    }
}
