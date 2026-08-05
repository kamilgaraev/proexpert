<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\File\CreatePersonalFolderRequest;
use App\Http\Requests\Api\V1\Admin\File\ListFilesRequest;
use App\Http\Requests\Api\V1\Admin\File\UploadPersonalFileRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Organization\OrganizationContext;
use App\Services\Storage\PersonalFileService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function trans_message;

final class PersonalFileController extends Controller
{
    public function __construct(private readonly PersonalFileService $personalFiles) {}

    public function index(ListFilesRequest $request): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $paginator = $this->personalFiles->paginate(
                $organizationId,
                $userId,
                $request->validated(),
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
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422);
        } catch (Throwable $exception) {
            $this->logFailure('personal_files.index_failed', $exception, $request);

            return AdminResponse::error(trans_message('files.load_failed'), 500);
        }
    }

    public function createFolder(CreatePersonalFolderRequest $request): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $validated = $request->validated();
            $folder = $this->personalFiles->createFolder(
                $organizationId,
                $userId,
                (string) $validated['name'],
                (string) ($validated['parent_path'] ?? ''),
            );

            return AdminResponse::success(
                $this->personalFiles->payload($folder, false),
                trans_message('files.folder_created'),
                201,
            );
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422);
        } catch (Throwable $exception) {
            $this->logFailure('personal_files.folder_create_failed', $exception, $request);

            return AdminResponse::error(trans_message('files.operation_failed'), 500);
        }
    }

    public function upload(UploadPersonalFileRequest $request): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $validated = $request->validated();
            $uploadedFile = $validated['file'] ?? null;
            if (! $uploadedFile instanceof UploadedFile) {
                throw new InvalidArgumentException('personal_file_upload_invalid');
            }
            $file = $this->personalFiles->upload(
                $organizationId,
                $userId,
                $uploadedFile,
                (string) ($validated['parent_path'] ?? ''),
            );

            return AdminResponse::success(
                $this->personalFiles->payload($file, false),
                trans_message('files.uploaded'),
                201,
            );
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('files.operation_failed'), 422);
        } catch (Throwable $exception) {
            $this->logFailure('personal_files.upload_failed', $exception, $request);

            return AdminResponse::error(trans_message('files.upload_failed'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            [$organizationId, $userId] = $this->scope($request);
            $this->personalFiles->delete($id, $organizationId, $userId);

            return AdminResponse::success(null, trans_message('files.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('files.not_found'), 404);
        } catch (Throwable $exception) {
            $this->logFailure('personal_files.delete_failed', $exception, $request, $id);

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
