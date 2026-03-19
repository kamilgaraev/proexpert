<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ReportController extends Controller
{
    public function financial(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'period_from' => ['required', 'date'],
                'period_to' => ['required', 'date', 'after_or_equal:period_from'],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where('organization_id', $organizationId),
                ],
                'report_type' => ['nullable', 'in:summary,detailed,by_project,by_counterparty'],
            ]);
            $periodFrom = $validated['period_from'];
            $periodTo = $validated['period_to'];

            $query = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->whereBetween('document_date', [$periodFrom, $periodTo]);

            if (!empty($validated['project_id'])) {
                $query->where('project_id', (int) $validated['project_id']);
            }

            $documents = $query->get();
            $projectNames = Project::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $documents->pluck('project_id')->filter()->unique()->all())
                ->pluck('name', 'id');

            $summary = [
                'total_invoiced' => (string) $documents->sum('amount'),
                'total_paid' => (string) $documents->sum('paid_amount'),
                'total_outstanding' => (string) $documents->sum('remaining_amount'),
                'documents_count' => $documents->count(),
                'transactions_count' => PaymentTransaction::query()
                    ->where('organization_id', $organizationId)
                    ->whereBetween('transaction_date', [$periodFrom, $periodTo])
                    ->count(),
            ];

            $byStatus = $documents->groupBy('status')->map(fn ($group) => (string) $group->sum('remaining_amount'))->toArray();

            $byDirection = [
                'incoming' => (string) $documents->where('direction', InvoiceDirection::INCOMING)->sum('amount'),
                'outgoing' => (string) $documents->where('direction', InvoiceDirection::OUTGOING)->sum('amount'),
            ];

            $byProject = $documents->groupBy('project_id')->map(function ($group, $projectId) use ($projectNames) {
                return [
                    'project_id' => $projectId ? (int) $projectId : null,
                    'project_name' => $projectId ? ($projectNames[(int) $projectId] ?? 'Без проекта') : 'Без проекта',
                    'invoiced' => (string) $group->sum('amount'),
                    'paid' => (string) $group->sum('paid_amount'),
                    'outstanding' => (string) $group->sum('remaining_amount'),
                ];
            })->values()->toArray();

            $topDebtors = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->where('direction', InvoiceDirection::INCOMING)
                ->whereIn('status', [
                    PaymentDocumentStatus::SUBMITTED,
                    PaymentDocumentStatus::APPROVED,
                    PaymentDocumentStatus::PARTIALLY_PAID,
                    PaymentDocumentStatus::SCHEDULED,
                ])
                ->with('counterpartyOrganization')
                ->get()
                ->groupBy('counterparty_organization_id')
                ->map(function ($group) {
                    $org = $group->first()->counterpartyOrganization;

                    return [
                        'organization_id' => $org?->id,
                        'organization_name' => $org?->name ?? 'Не указано',
                        'debt_amount' => (string) $group->sum('remaining_amount'),
                        'overdue_documents_count' => $group->filter(fn ($document) => $document->isOverdue())->count(),
                    ];
                })
                ->sortByDesc('debt_amount')
                ->take(10)
                ->values()
                ->toArray();

            return AdminResponse::success([
                'period' => [
                    'from' => $periodFrom,
                    'to' => $periodTo,
                ],
                'meta' => [
                    'report_type' => $validated['report_type'] ?? 'summary',
                    'project_id' => $validated['project_id'] ?? null,
                ],
                'summary' => $summary,
                'by_status' => $byStatus,
                'by_direction' => $byDirection,
                'by_project' => $byProject,
                'top_debtors' => $topDebtors,
            ], trans_message('payments.reports.generated'));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.reports.financial.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reports.generate_error'), 500);
        }
    }

    public function export(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'report_type' => ['required', 'string'],
                'format' => ['required', 'in:excel,pdf'],
                'period_from' => ['required', 'date'],
                'period_to' => ['required', 'date', 'after_or_equal:period_from'],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where('organization_id', $organizationId),
                ],
                'filters' => ['nullable', 'array'],
            ]);

            return AdminResponse::error(trans_message('payments.reports.export_not_ready'), 501, [
                'requested' => $validated,
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.reports.export.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.reports.export_error'), 500);
        }
    }
}
