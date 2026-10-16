<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageIssueRegisterService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DesignIssueRegisterExportController extends Controller
{
    public function __construct(private readonly DesignManagementService $packages, private readonly DesignPackageIssueRegisterService $register) {}

    public function __invoke(Request $request, int $packageId): JsonResponse|StreamedResponse
    {
        $request->validate([
            'workflow_event_id' => ['nullable', 'integer', 'min:1'],
            'format_version' => ['sometimes', 'integer', 'in:1,2'],
        ]);
        try {
            $package = $this->packages->findPackage((int) $request->attributes->get('current_organization_id'), $packageId, $request->user(), 'design-management.export');
            if ($package === null) {
                return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
            }
            $register = $this->register->build($package, $request->integer('workflow_event_id') ?: null, $request->user());
            $includeVersionNumber = $request->integer('format_version', 1) === 2;
            return response()->streamDownload(static function () use ($register, $includeVersionNumber): void {
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    return;
                }
                fwrite($output, "\xEF\xBB\xBF");
                $safe = static fn (mixed $value): string => preg_match('/^[=+@\-\t\r]/u', (string) $value) === 1 ? "'".(string) $value : (string) $value;
                $headers = ['Комплект', 'Выдача', 'Дата', 'Раздел', 'Документ', 'Название', 'Версия', 'Ревизия', 'Файл', 'SHA256', 'Листов'];
                if ($includeVersionNumber) {
                    array_splice($headers, 6, 1, ['Номер версии', 'ID версии']);
                }
                fputcsv($output, $headers, ';', '"', '');
                foreach ($register['sections'] as $section) {
                    foreach ($section['documents'] as $document) {
                        $row = [$register['package']['title'], $register['release']['workflow_event_id'] ?? '', $register['release']['issued_at'] ?? '', $section['code'], $document['document_code'], $document['title'], $document['current_version_id'], $document['revision'], $document['source_original_name'], $document['source_sha256'] ?? '', $document['sheets_count']];
                        if ($includeVersionNumber) {
                            array_splice($row, 6, 0, [$document['version_number'] ?? '']);
                        }
                        fputcsv($output, array_map($safe, $row), ';', '"', '');
                    }
                }
                fclose($output);
            }, 'design-register-'.$packageId.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
