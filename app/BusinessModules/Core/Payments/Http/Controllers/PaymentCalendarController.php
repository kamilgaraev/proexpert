<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarContractService;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class PaymentCalendarController extends Controller
{
    private const MAX_CALENDAR_RANGE_DAYS = 92;

    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
        private readonly PaymentCalendarContractService $calendarContractService,
        private readonly PaymentCalendarSourceService $calendarSourceService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start' => ['required', 'date'],
                'end' => ['required', 'date', 'after_or_equal:start'],
                'project_id' => ['nullable', 'integer'],
                'counterparty_id' => ['nullable', 'integer'],
                'budget_article_id' => ['nullable', 'string', 'max:64'],
                'responsibility_center_id' => ['nullable', 'string', 'max:64'],
                'currency' => ['nullable', 'string', 'size:3'],
                'direction' => ['nullable', Rule::in([
                    PaymentCalendarItem::DIRECTION_INFLOW,
                    PaymentCalendarItem::DIRECTION_OUTFLOW,
                ])],
                'bucket' => ['nullable', Rule::in([
                    PaymentCalendarItem::BUCKET_FACT,
                    PaymentCalendarItem::BUCKET_SCHEDULED,
                    PaymentCalendarItem::BUCKET_APPROVED,
                    PaymentCalendarItem::BUCKET_RESERVED,
                    PaymentCalendarItem::BUCKET_OVERDUE,
                    PaymentCalendarItem::BUCKET_BUDGET_PLAN,
                    PaymentCalendarItem::BUCKET_MANUAL,
                ])],
                'source_type' => ['nullable', Rule::in([
                    'payment_document',
                    'payment_schedule',
                    'payment_transaction',
                    'budget_limit_reservation',
                    'budget_amount',
                ])],
            ]);

            $this->assertSupportedRange($validated['start'], $validated['end']);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $filters = new PaymentCalendarSourceFilters(
                organizationId: $organizationId,
                periodStart: $validated['start'],
                periodEnd: $validated['end'],
                projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                counterpartyId: isset($validated['counterparty_id']) ? (int) $validated['counterparty_id'] : null,
                budgetArticleId: $validated['budget_article_id'] ?? null,
                responsibilityCenterId: $validated['responsibility_center_id'] ?? null,
                currency: $validated['currency'] ?? null,
                direction: $validated['direction'] ?? null,
                bucket: $validated['bucket'] ?? null,
                sourceType: $validated['source_type'] ?? null,
            );

            return AdminResponse::success(
                $this->calendarContractService->build($filters),
                trans_message('payments.calendar.loaded')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.calendar.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'filters' => $request->only([
                    'start',
                    'end',
                    'project_id',
                    'counterparty_id',
                    'budget_article_id',
                    'responsibility_center_id',
                    'currency',
                    'direction',
                    'bucket',
                    'source_type',
                ]),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.calendar.load_error'), 500);
        }
    }

    public function reschedule(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => ['required', 'date'],
                'reason' => ['nullable', 'required_without:budget_override_reason', 'string', 'max:1000'],
                'budget_override_reason' => ['nullable', 'required_without:reason', 'string', 'max:1000'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail((int) $id);

            $calendarItem = $this->calendarSourceService->fromPaymentDocument($document);

            if (!$calendarItem instanceof PaymentCalendarItem || !$calendarItem->editable) {
                return AdminResponse::error(trans_message('payments.calendar.reschedule_not_editable'), 422);
            }

            $reason = $this->rescheduleReason($validated);
            $document = $this->paymentDocumentService->schedule(
                $document,
                new \DateTime($validated['date']),
                $request->user(),
                $reason
            );

            return AdminResponse::success([
                'id' => $document->id,
                'scheduled_at' => $document->scheduled_at?->format('Y-m-d'),
                'status' => $this->statusValue($document->status),
            ], trans_message('payments.calendar.rescheduled'));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('payments.calendar.reschedule.error', [
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.calendar.reschedule_error'), 500);
        }
    }

    private function rescheduleReason(array $validated): ?string
    {
        $reason = $validated['reason'] ?? $validated['budget_override_reason'] ?? null;

        if (!is_string($reason) || trim($reason) === '') {
            return null;
        }

        return trim($reason);
    }

    private function assertSupportedRange(string $start, string $end): void
    {
        $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;

        if ($days > self::MAX_CALENDAR_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end' => [trans_message('payments.calendar.range_too_large')],
            ]);
        }
    }

    private function statusValue(mixed $status): string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return (string) $status;
    }
}
