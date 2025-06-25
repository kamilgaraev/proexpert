<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractPerformanceAct;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use App\Services\Export\ExcelExporterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Storage;
use App\Models\File;

class ActReportsController extends Controller
{
    protected ExcelExporterService $excelExporter;

    public function __construct(ExcelExporterService $excelExporter)
    {
        $this->excelExporter = $excelExporter;
        $this->middleware('auth:api_admin');
        $this->middleware('organization.context');
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить все акты организации с фильтрацией
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json([
                    'error' => 'Не определена организация пользователя'
                ], 400);
            }

            $query = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor', 
                'completedWorks'
            ])->whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });

            // Фильтры
            if ($request->filled('contract_id')) {
                $query->where('contract_id', $request->contract_id);
            }

            if ($request->filled('project_id')) {
                $query->whereHas('contract', function ($q) use ($request) {
                    $q->where('project_id', $request->project_id);
                });
            }

            if ($request->filled('contractor_id')) {
                $query->whereHas('contract', function ($q) use ($request) {
                    $q->where('contractor_id', $request->contractor_id);
                });
            }

            if ($request->filled('is_approved')) {
                $query->where('is_approved', $request->boolean('is_approved'));
            }

            if ($request->filled('date_from')) {
                $query->where('act_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->where('act_date', '<=', $request->date_to);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('act_document_number', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereHas('contract', function ($contractQuery) use ($search) {
                          $contractQuery->where('contract_number', 'like', "%{$search}%");
                      });
                });
            }

            // Сортировка
            $sortBy = $request->get('sort_by', 'act_date');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortBy, $sortDirection);

            // Пагинация
            $perPage = $request->get('per_page', 15);
            $acts = $query->paginate($perPage);

            return response()->json([
                'data' => new ContractPerformanceActCollection($acts),
                'pagination' => [
                    'current_page' => $acts->currentPage(),
                    'last_page' => $acts->lastPage(),
                    'per_page' => $acts->perPage(),
                    'total' => $acts->total(),
                ],
                'statistics' => [
                    'total_acts' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->count(),
                    'approved_acts' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->where('is_approved', true)->count(),
                    'total_amount' => ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId);
                    })->sum('amount'),
                ],
                'message' => 'Акты получены успешно'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при получении актов',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить список контрактов для создания актов
     */
    public function getContracts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            $contracts = Contract::with(['project', 'contractor'])
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->get()
                ->map(function ($contract) {
                    return [
                        'id' => $contract->id,
                        'contract_number' => $contract->contract_number,
                        'project_name' => $contract->project->name ?? 'Не указан',
                        'contractor_name' => $contract->contractor->name ?? 'Не указан',
                        'contract_date' => $contract->contract_date?->format('d.m.Y'),
                        'start_date' => $contract->start_date?->format('d.m.Y'),
                        'end_date' => $contract->end_date?->format('d.m.Y'),
                    ];
                });

            return response()->json([
                'data' => $contracts,
                'message' => 'Контракты получены успешно'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при получении контрактов',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Создать новый акт
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            $request->validate([
                'contract_id' => 'required|integer|exists:contracts,id',
                'act_document_number' => 'required|string|max:255',
                'act_date' => 'required|date',
                'description' => 'nullable|string',
                'work_ids' => 'nullable|array',
                'work_ids.*' => 'integer|exists:completed_works,id'
            ]);

            // Проверяем что контракт принадлежит организации
            $contract = Contract::where('id', $request->contract_id)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$contract) {
                return response()->json(['error' => 'Контракт не найден или доступ запрещен'], 403);
            }

            DB::beginTransaction();

            // Создаем акт
            $act = ContractPerformanceAct::create([
                'contract_id' => $contract->id,
                'act_document_number' => $request->act_document_number,
                'act_date' => $request->act_date,
                'description' => $request->description,
                'amount' => 0,
                'is_approved' => false
            ]);

            // Если переданы работы - прикрепляем их
            if ($request->has('work_ids') && !empty($request->work_ids)) {
                $validWorks = CompletedWork::whereIn('id', $request->work_ids)
                    ->where('contract_id', $contract->id)
                    ->where('status', 'confirmed')
                    ->get();

                $pivotData = [];
                $totalAmount = 0;

                foreach ($validWorks as $work) {
                    $pivotData[$work->id] = [
                        'included_quantity' => $work->quantity,
                        'included_amount' => $work->total_amount,
                        'notes' => null,
                    ];
                    $totalAmount += $work->total_amount;
                }

                $act->completedWorks()->attach($pivotData);
                $act->update(['amount' => $totalAmount]);
            }

            DB::commit();

            $act->load([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.user',
                'completedWorks.materials'
            ]);

            return response()->json([
                'data' => new ContractPerformanceActResource($act),
                'message' => 'Акт создан успешно'
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Ошибка создания акта из отчетов', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при создании акта',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить детали акта
     */
    public function show(ContractPerformanceAct $act): JsonResponse
    {
        try {
            $user = request()->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            // Загружаем связь contract для проверки
            $act->load('contract');
            
            // Проверяем принадлежность акта организации
            if ($act->contract->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $act->load([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ]);

            return response()->json([
                'data' => new ContractPerformanceActResource($act),
                'message' => 'Акт получен успешно'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при получении акта',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт акта в PDF
     */
    public function exportPdf(Request $request, ContractPerformanceAct $act)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            if ($act->contract->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $act->load([
                'contract.project',
                'contract.contractor',
                'contract.organization',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ]);

            $data = [
                'act' => $act,
                'contract' => $act->contract,
                'project' => $act->contract->project ?? (object)['name' => 'Не указан'],
                'contractor' => $act->contract->contractor ?? (object)['name' => 'Не указан'],
                'works' => $act->completedWorks ?? collect(),
                'total_amount' => $act->amount ?? 0,
                'total_amount_words' => $this->numberToWords($act->amount ?? 0),
                'generated_at' => now()->format('d.m.Y H:i')
            ];

            $pdf = Pdf::loadView('reports.act-report-pdf', $data);
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => true
            ]);

            $filename = "act_" . preg_replace('/[^A-Za-z0-9\-_]/', '_', $act->act_document_number) . "_" . now()->format('Y-m-d') . ".pdf";
            $path = "documents/acts/{$organizationId}/{$filename}";

            // Генерируем PDF контент
            $pdfContent = $pdf->output();
            
            // Сохраняем в S3 (в фоне)
            try {
                Storage::disk('s3')->put($path, $pdfContent, 'public');

                // Создаем запись в БД только если файла еще нет
                $existingFile = File::where('fileable_type', ContractPerformanceAct::class)
                    ->where('fileable_id', $act->id)
                    ->where('type', 'pdf_export')
                    ->where('organization_id', $organizationId)
                    ->first();

                if (!$existingFile) {
                    File::create([
                        'organization_id' => $organizationId,
                        'fileable_id' => $act->id,
                        'fileable_type' => ContractPerformanceAct::class,
                        'user_id' => $user->id,
                        'name' => $filename,
                        'original_name' => "Акт_{$act->act_document_number}.pdf",
                        'path' => $path,
                        'mime_type' => 'application/pdf',
                        'size' => strlen($pdfContent),
                        'disk' => 's3',
                        'type' => 'pdf_export',
                        'category' => 'act_report'
                    ]);
                }

                Log::info('PDF акт сохранен в S3', [
                    'act_id' => $act->id,
                    'path' => $path,
                    'organization_id' => $organizationId
                ]);
            } catch (Exception $e) {
                Log::error('Ошибка сохранения акта в S3', [
                    'act_id' => $act->id,
                    'error' => $e->getMessage()
                ]);
                // Продолжаем выполнение - фронт все равно получит файл
            }

            // Возвращаем файл для скачивания как раньше
            return $pdf->download($filename);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта PDF акта из отчетов', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при экспорте в PDF',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Скачать сохраненный PDF акт
     */
    public function downloadPdf(Request $request, ContractPerformanceAct $act, File $file)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId || $file->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            if ($file->fileable_id !== $act->id || $file->fileable_type !== ContractPerformanceAct::class) {
                return response()->json(['error' => 'Файл не принадлежит данному акту'], 403);
            }

            if (!Storage::disk($file->disk)->exists($file->path)) {
                return response()->json(['error' => 'Файл не найден в хранилище'], 404);
            }

            return Storage::disk($file->disk)->download($file->path, $file->original_name);

        } catch (Exception $e) {
            Log::error('Ошибка скачивания PDF акта', [
                'act_id' => $act->id,
                'file_id' => $file->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при скачивании файла',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Экспорт акта в Excel
     */
    public function exportExcel(Request $request, ContractPerformanceAct $act)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            if ($act->contract->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $act->load([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ]);

            $headers = [
                'Наименование работы',
                'Единица измерения',
                'Количество',
                'Цена за единицу',
                'Сумма',
                'Материалы',
                'Дата выполнения',
                'Исполнитель'
            ];

            $exportData = [];
            $completedWorks = $act->completedWorks ?? collect();
            
            foreach ($completedWorks as $work) {
                $materials = '';
                if ($work->materials && $work->materials->isNotEmpty()) {
                    $materials = $work->materials->map(function ($material) {
                        $quantity = $material->pivot->quantity ?? 0;
                        $unit = $material->unit ?? '';
                        return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                    })->join(', ');
                }

                $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
                $executorName = $work->user ? $work->user->name : 'Не указан';
                $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

                $exportData[] = [
                    $workTypeName,
                    $work->unit ?? '',
                    $work->quantity ?? 0,
                    $work->unit_price ?? 0,
                    $work->total_amount ?? 0,
                    $materials,
                    $completionDate,
                    $executorName
                ];
            }

            // Если нет работ, добавляем пустую строку
            if (empty($exportData)) {
                $exportData[] = [
                    'Нет выполненных работ',
                    '-',
                    0,
                    0,
                    0,
                    '-',
                    '-',
                    '-'
                ];
            }

            $filename = "act_" . preg_replace('/[^A-Za-z0-9\-_]/', '_', $act->act_document_number) . "_" . now()->format('Y-m-d') . ".xlsx";
            $path = "documents/acts/{$organizationId}/{$filename}";

            // Сохраняем в S3 (в фоне)
            try {
                // Генерируем Excel контент через PhpSpreadsheet
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();

                // Заголовки
                $colIndex = 0;
                foreach ($headers as $header) {
                    $cell = chr(65 + $colIndex) . '1';
                    $sheet->setCellValue($cell, $header);
                    $colIndex++;
                }

                // Данные
                $rowIndex = 2;
                foreach ($exportData as $rowArray) {
                    $colIndex = 0;
                    foreach ($rowArray as $value) {
                        $cell = chr(65 + $colIndex) . $rowIndex;
                        $sheet->setCellValue($cell, $value);
                        $colIndex++;
                    }
                    $rowIndex++;
                }

                // Генерируем Excel в память
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $tempFile = tempnam(sys_get_temp_dir(), 'act_excel_');
                $writer->save($tempFile);
                $excelContent = file_get_contents($tempFile);
                unlink($tempFile);

                Storage::disk('s3')->put($path, $excelContent, 'public');

                // Создаем запись в БД только если файла еще нет
                $existingFile = File::where('fileable_type', ContractPerformanceAct::class)
                    ->where('fileable_id', $act->id)
                    ->where('type', 'excel_export')
                    ->where('organization_id', $organizationId)
                    ->first();

                if (!$existingFile) {
                    File::create([
                        'organization_id' => $organizationId,
                        'fileable_id' => $act->id,
                        'fileable_type' => ContractPerformanceAct::class,
                        'user_id' => $user->id,
                        'name' => $filename,
                        'original_name' => "Акт_{$act->act_document_number}.xlsx",
                        'path' => $path,
                        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'size' => strlen($excelContent),
                        'disk' => 's3',
                        'type' => 'excel_export',
                        'category' => 'act_report'
                    ]);
                }

                Log::info('Excel акт сохранен в S3', [
                    'act_id' => $act->id,
                    'path' => $path,
                    'organization_id' => $organizationId
                ]);
            } catch (Exception $e) {
                Log::error('Ошибка сохранения Excel акта в S3', [
                    'act_id' => $act->id,
                    'error' => $e->getMessage()
                ]);
                // Продолжаем выполнение - фронт все равно получит файл
            }

            // Возвращаем файл для скачивания как раньше
            return $this->excelExporter->streamDownload($filename, $headers, $exportData);

        } catch (Exception $e) {
            Log::error('Ошибка экспорта Excel акта из отчетов', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при экспорте в Excel',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Массовый экспорт актов в Excel
     */
    public function bulkExportExcel(Request $request)
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            $actIds = $request->input('act_ids', []);
            
            if (empty($actIds)) {
                return response()->json([
                    'error' => 'Не выбраны акты для экспорта'
                ], 400);
            }

            $acts = ContractPerformanceAct::with([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.materials',
                'completedWorks.user'
            ])->whereHas('contract', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })->whereIn('id', $actIds)->get();

            $headers = [
                'Номер акта',
                'Контракт',
                'Проект',
                'Подрядчик',
                'Дата акта',
                'Сумма',
                'Статус',
                'Наименование работы',
                'Единица измерения',
                'Количество',
                'Цена за единицу',
                'Сумма работы',
                'Материалы',
                'Дата выполнения',
                'Исполнитель'
            ];

            $exportData = [];
            foreach ($acts as $act) {
                foreach ($act->completedWorks as $work) {
                    $materials = '';
                    if ($work->materials && $work->materials->isNotEmpty()) {
                        $materials = $work->materials->map(function ($material) {
                            $quantity = $material->pivot->quantity ?? 0;
                            $unit = $material->unit ?? '';
                            return $material->name . ' (' . $quantity . ' ' . $unit . ')';
                        })->join(', ');
                    }

                    $workTypeName = $work->workType ? $work->workType->name : 'Не указан';
                    $executorName = $work->user ? $work->user->name : 'Не указан';
                    $completionDate = $work->completion_date ? $work->completion_date->format('d.m.Y') : 'Не указана';

                    $exportData[] = [
                        $act->act_document_number,
                        $act->contract->contract_number ?? '',
                        $act->contract->project->name ?? '',
                        $act->contract->contractor->name ?? '',
                        $act->act_date ? $act->act_date->format('d.m.Y') : '',
                        $act->amount ?? 0,
                        $act->is_approved ? 'Утвержден' : 'Не утвержден',
                        $workTypeName,
                        $work->unit ?? '',
                        $work->quantity ?? 0,
                        $work->unit_price ?? 0,
                        $work->total_amount ?? 0,
                        $materials,
                        $completionDate,
                        $executorName
                    ];
                }
            }

            $filename = "acts_bulk_export_" . now()->format('Y-m-d_H-i-s') . ".xlsx";

            return $this->excelExporter->streamDownload($filename, $headers, $exportData);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Ошибка при массовом экспорте',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить доступные работы для включения в акт
     */
    public function getAvailableWorks(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            // Загружаем связь contract для проверки
            $act->load('contract');
            
            Log::info('Проверка доступа к акту в getAvailableWorks', [
                'act_id' => $act->id,
                'act_contract_org_id' => $act->contract->organization_id,
                'user_org_id' => $organizationId,
                'contract_id' => $act->contract_id
            ]);
            
            // Проверяем принадлежность акта организации
            if ($act->contract->organization_id !== $organizationId) {
                Log::warning('Доступ к акту запрещен в getAvailableWorks', [
                    'act_id' => $act->id,
                    'expected_org_id' => $organizationId,
                    'actual_org_id' => $act->contract->organization_id
                ]);
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $act->load('completedWorks');

            // Получаем подтвержденные работы по контракту которые еще не включены в утвержденные акты
            $works = CompletedWork::where('contract_id', $act->contract_id)
                ->where('status', 'confirmed')
                ->with(['workType:id,name', 'user:id,name'])
                ->get();

            $availableWorks = $works->map(function ($work) use ($act) {
                return [
                    'id' => $work->id,
                    'work_type_name' => $work->workType->name ?? 'Не указано',
                    'user_name' => $work->user->name ?? 'Не указано',
                    'quantity' => (float) $work->quantity,
                    'price' => (float) $work->price,
                    'total_amount' => (float) $work->total_amount,
                    'completion_date' => $work->completion_date,
                    'is_included_in_approved_act' => $this->isWorkIncludedInApprovedAct($work->id),
                    'is_included_in_current_act' => $act->completedWorks->contains('id', $work->id),
                ];
            });

            return response()->json([
                'data' => $availableWorks,
                'message' => 'Доступные работы получены успешно'
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка получения доступных работ для акта', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при получении доступных работ',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить состав работ в акте
     */
    public function updateWorks(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            // Загружаем связь contract для проверки
            $act->load('contract');
            
            // Проверяем принадлежность акта организации
            if ($act->contract->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $workIds = $request->input('work_ids', []);
            
            // Проверяем что все работы принадлежат тому же контракту
            $validWorks = CompletedWork::whereIn('id', $workIds)
                ->where('contract_id', $act->contract_id)
                ->where('status', 'confirmed')
                ->pluck('id')
                ->toArray();

            DB::transaction(function () use ($act, $validWorks) {
                // Удаляем все существующие связи
                $act->completedWorks()->detach();
                
                // Получаем данные работ для правильного заполнения пивот таблицы
                $works = CompletedWork::whereIn('id', $validWorks)->get();
                $pivotData = [];
                
                foreach ($works as $work) {
                    $pivotData[$work->id] = [
                        'included_quantity' => $work->quantity,
                        'included_amount' => $work->total_amount,
                        'notes' => null,
                    ];
                }
                
                // Прикрепляем работы с заполнением всех полей
                $act->completedWorks()->attach($pivotData);
                
                // Пересчитываем сумму акта
                $totalAmount = $works->sum('total_amount');
                $act->update(['amount' => $totalAmount]);
            });

            $act->load([
                'completedWorks.workType',
                'completedWorks.user',
                'completedWorks.materials'
            ]);

            return response()->json([
                'data' => new ContractPerformanceActResource($act),
                'message' => 'Состав работ акта обновлен успешно'
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка обновления работ в акте', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при обновлении работ в акте',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Обновить основную информацию акта
     */
    public function update(Request $request, ContractPerformanceAct $act): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $user->organization_id ?? $user->current_organization_id;

            if (!$organizationId) {
                return response()->json(['error' => 'Не определена организация пользователя'], 400);
            }

            // Загружаем связь contract для проверки
            $act->load('contract');
            
            // Проверяем принадлежность акта организации
            if ($act->contract->organization_id !== $organizationId) {
                return response()->json(['error' => 'Доступ запрещен'], 403);
            }

            $request->validate([
                'act_document_number' => 'sometimes|string|max:255',
                'act_date' => 'sometimes|date',
                'description' => 'sometimes|string|nullable',
                'is_approved' => 'sometimes|boolean',
            ]);

            $updateData = $request->only([
                'act_document_number',
                'act_date', 
                'description',
                'is_approved'
            ]);

            $act->update($updateData);

            $act->load([
                'contract.project',
                'contract.contractor',
                'completedWorks.workType',
                'completedWorks.user',
                'completedWorks.materials'
            ]);

            return response()->json([
                'data' => new ContractPerformanceActResource($act),
                'message' => 'Акт обновлен успешно'
            ]);

        } catch (Exception $e) {
            Log::error('Ошибка обновления акта', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Ошибка при обновлении акта',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Проверить включена ли работа в утвержденный акт
     */
    protected function isWorkIncludedInApprovedAct(int $workId): bool
    {
        return DB::table('performance_act_completed_works')
            ->join('contract_performance_acts', 'performance_act_completed_works.performance_act_id', '=', 'contract_performance_acts.id')
            ->where('performance_act_completed_works.completed_work_id', $workId)
            ->where('contract_performance_acts.is_approved', true)
            ->exists();
    }

    /**
     * Преобразование числа в слова (рубли)
     */
    protected function numberToWords(float $number): string
    {
        if ($number == 0) {
            return 'ноль';
        }

        $units = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

        $result = '';
        $int_number = (int) $number;

        // Миллионы
        $millions = (int) ($int_number / 1000000);
        if ($millions > 0) {
            $result .= $this->convertHundreds($millions) . ' миллион';
            if ($millions % 10 >= 2 && $millions % 10 <= 4 && ($millions % 100 < 10 || $millions % 100 >= 20)) {
                $result .= 'а ';
            } elseif ($millions % 10 >= 5 || $millions % 10 == 0 || ($millions % 100 >= 10 && $millions % 100 <= 20)) {
                $result .= 'ов ';
            } else {
                $result .= ' ';
            }
            $int_number %= 1000000;
        }

        // Тысячи
        $thousands = (int) ($int_number / 1000);
        if ($thousands > 0) {
            if ($thousands == 1) {
                $result .= 'одна тысяча ';
            } elseif ($thousands == 2) {
                $result .= 'две тысячи ';
            } else {
                $result .= $this->convertHundreds($thousands) . ' тысяч ';
            }
            $int_number %= 1000;
        }

        // Сотни, десятки, единицы
        if ($int_number > 0) {
            $result .= $this->convertHundreds($int_number);
        }

        return trim($result);
    }

    /**
     * Вспомогательный метод для преобразования сотен
     */
    protected function convertHundreds(int $number): string
    {
        $units = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

        $result = '';
        $h = (int) ($number / 100) % 10;
        $t = (int) ($number / 10) % 10;
        $u = $number % 10;

        if ($h > 0) {
            $result .= $hundreds[$h] . ' ';
        }

        if ($t == 1) {
            $result .= $teens[$u] . ' ';
        } else {
            if ($t > 1) {
                $result .= $tens[$t] . ' ';
            }
            if ($u > 0) {
                $result .= $units[$u] . ' ';
            }
        }

        return trim($result);
    }
} 