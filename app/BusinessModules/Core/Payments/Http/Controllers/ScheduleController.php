<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'payment_document_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'status' => ['nullable', 'string'],
            ]);

            $query = PaymentSchedule::query()
                ->with(['paymentDocument.project'])
                ->whereHas('paymentDocument', fn ($builder) => $builder->where('organization_id', $organizationId));

            if (!empty($validated['payment_document_id'])) {
                $query->where('payment_document_id', $validated['payment_document_id']);
            }

            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            $schedules = $query->orderBy('due_date')->get();

            return AdminResponse::success([
                'items' => $schedules->map(fn (PaymentSchedule $schedule) => $this->formatSchedule($schedule)),
                'summary' => [
                    'total' => $schedules->count(),
                    'total_amount' => (float) $schedules->sum('amount'),
                    'pending_count' => $schedules->where('status', 'pending')->count(),
                    'pending_amount' => (float) $schedules->where('status', 'pending')->sum('amount'),
                ],
            ], trans_message('payments.schedule.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.schedules.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.schedule.load_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'payment_document_id' => [
                    'required',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'installments' => ['required', 'array', 'min:1'],
                'installments.*.installment_number' => ['required', 'integer', 'min:1'],
                'installments.*.due_date' => ['required', 'date'],
                'installments.*.amount' => ['required', 'numeric', 'min:0.01'],
                'installments.*.notes' => ['nullable', 'string', 'max:500'],
            ]);

            $document = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->findOrFail((int) $validated['payment_document_id']);

            $totalScheduleAmount = (float) collect($validated['installments'])->sum('amount');
            if ((float) $document->amount !== $totalScheduleAmount) {
                return AdminResponse::error(trans_message('payments.schedule.sum_mismatch'), 422);
            }

            $schedules = DB::transaction(function () use ($validated): array {
                $created = [];

                foreach ($validated['installments'] as $installment) {
                    $created[] = PaymentSchedule::create([
                        'payment_document_id' => $validated['payment_document_id'],
                        'installment_number' => $installment['installment_number'],
                        'due_date' => $installment['due_date'],
                        'amount' => $installment['amount'],
                        'status' => 'pending',
                        'notes' => $installment['notes'] ?? null,
                    ]);
                }

                return $created;
            });

            return AdminResponse::success(
                collect($schedules)->map(fn (PaymentSchedule $schedule) => $this->formatSchedule($schedule->load('paymentDocument.project'))),
                trans_message('payments.schedule.created'),
                201
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.schedule.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.schedule.create_error'), 500);
        }
    }

    public function upcoming(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            ]);
            $days = (int) ($validated['days'] ?? 30);

            $schedules = PaymentSchedule::query()
                ->with(['paymentDocument.project', 'paymentDocument.counterpartyOrganization'])
                ->whereHas('paymentDocument', fn ($query) => $query->where('organization_id', $organizationId))
                ->where('status', 'pending')
                ->whereBetween('due_date', [now(), now()->addDays($days)])
                ->orderBy('due_date')
                ->get()
                ->map(fn (PaymentSchedule $schedule) => $this->formatSchedule($schedule, true));

            return AdminResponse::paginated($schedules, [
                'period_days' => $days,
                'total_count' => $schedules->count(),
                'total_amount' => $schedules->sum('amount'),
            ], trans_message('payments.schedule.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.schedules.upcoming.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.schedule.load_error'), 500);
        }
    }

    public function templates(Request $request): JsonResponse
    {
        try {
            $templates = [
                [
                    'id' => 'equal_2',
                    'name' => 'Равными платежами (2 платежа)',
                    'description' => 'График из 2 равных платежей каждые 30 дней',
                    'config' => ['schedule_type' => 'equal_installments', 'installments_count' => 2, 'interval_days' => 30],
                ],
                [
                    'id' => 'equal_3',
                    'name' => 'Равными платежами (3 платежа)',
                    'description' => 'График из 3 равных платежей каждые 30 дней',
                    'config' => ['schedule_type' => 'equal_installments', 'installments_count' => 3, 'interval_days' => 30],
                ],
                [
                    'id' => 'equal_4',
                    'name' => 'Равными платежами (4 платежа)',
                    'description' => 'График из 4 равных платежей каждые 30 дней',
                    'config' => ['schedule_type' => 'equal_installments', 'installments_count' => 4, 'interval_days' => 30],
                ],
                [
                    'id' => 'advance_30',
                    'name' => 'Аванс 30%',
                    'description' => 'Аванс 30%, промежуточный 50%, финальный 20%',
                    'config' => ['schedule_type' => 'percentage_based', 'percentages' => [30, 50, 20], 'interval_days' => 30],
                ],
                [
                    'id' => 'advance_50',
                    'name' => 'Аванс 50%',
                    'description' => 'Аванс 50%, финальный 50%',
                    'config' => ['schedule_type' => 'percentage_based', 'percentages' => [50, 50], 'interval_days' => 30],
                ],
                [
                    'id' => 'advance_30_fact',
                    'name' => 'Аванс 30% + по факту',
                    'description' => 'Аванс 30%, остальное по актам выполненных работ',
                    'config' => ['schedule_type' => 'advance_and_fact', 'advance_percentage' => 30, 'fact_based' => true],
                ],
                [
                    'id' => 'monthly',
                    'name' => 'Ежемесячно равными платежами',
                    'description' => 'График ежемесячных равных платежей',
                    'config' => ['schedule_type' => 'equal_installments', 'installments_count' => 12, 'interval_days' => 30],
                ],
                [
                    'id' => 'quarterly',
                    'name' => 'Ежеквартально',
                    'description' => 'График поквартальных равных платежей',
                    'config' => ['schedule_type' => 'equal_installments', 'installments_count' => 4, 'interval_days' => 90],
                ],
            ];

            return AdminResponse::success($templates, trans_message('payments.schedule.templates_loaded'));
        } catch (\Exception $e) {
            Log::error('payments.schedules.templates.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.schedule.load_error'), 500);
        }
    }

    public function overdue(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $schedules = PaymentSchedule::query()
                ->with(['paymentDocument.project', 'paymentDocument.counterpartyOrganization'])
                ->whereHas('paymentDocument', fn ($query) => $query->where('organization_id', $organizationId))
                ->where('status', 'pending')
                ->where('due_date', '<', now())
                ->orderBy('due_date')
                ->get()
                ->map(fn (PaymentSchedule $schedule) => $this->formatSchedule($schedule, true));

            return AdminResponse::paginated($schedules, [
                'total_count' => $schedules->count(),
                'total_amount' => $schedules->sum('amount'),
            ], trans_message('payments.schedule.loaded'));
        } catch (\Exception $e) {
            Log::error('payments.schedules.overdue.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.schedule.load_error'), 500);
        }
    }

    private function formatSchedule(PaymentSchedule $schedule, bool $extended = false): array
    {
        $document = $schedule->paymentDocument;
        $counterparty = $document?->counterpartyOrganization?->name ?? $document?->getPayeeName();

        $data = [
            'id' => $schedule->id,
            'payment_document_id' => $schedule->payment_document_id,
            'installment_number' => $schedule->installment_number,
            'due_date' => $schedule->due_date,
            'amount' => (float) $schedule->amount,
            'status' => $schedule->status,
            'notes' => $schedule->notes,
            'payment_document' => $document ? [
                'id' => $document->id,
                'document_number' => $document->document_number,
                'document_type' => is_object($document->document_type) ? $document->document_type->value : $document->document_type,
                'invoice_type' => $document->invoice_type ? (is_object($document->invoice_type) ? $document->invoice_type->value : $document->invoice_type) : null,
                'direction' => $document->direction ? (is_object($document->direction) ? $document->direction->value : $document->direction) : null,
                'project_name' => $document->project?->name,
                'counterparty' => $counterparty,
            ] : null,
        ];

        if ($extended) {
            $today = now();
            $data['days_offset'] = $today->diffInDays($schedule->due_date, false);
            $data['is_overdue'] = $schedule->due_date < $today;
            $data['is_due_soon'] = $schedule->due_date >= $today && $schedule->due_date <= $today->copy()->addDays(7);
        }

        return $data;
    }
}
