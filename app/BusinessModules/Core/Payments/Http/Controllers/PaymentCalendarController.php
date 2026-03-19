<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PaymentCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start' => ['required', 'date'],
                'end' => ['required', 'date', 'after_or_equal:start'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $documents = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->where(function ($query) use ($validated): void {
                    $query->whereBetween('due_date', [$validated['start'], $validated['end']])
                        ->orWhereBetween('scheduled_at', [$validated['start'], $validated['end']]);
                })
                ->get();

            $events = $documents->map(function (PaymentDocument $document): array {
                $date = $document->scheduled_at ?? $document->due_date;

                return [
                    'id' => $document->id,
                    'title' => $document->getPayerName() . ' - ' . $document->amount,
                    'start' => $date?->format('Y-m-d'),
                    'backgroundColor' => $this->getColorForStatus($document->status->value),
                    'extendedProps' => [
                        'amount' => $document->amount,
                        'status' => $document->status->value,
                        'type' => $document->document_type->value,
                    ],
                ];
            });

            return AdminResponse::success($events, trans_message('payments.calendar.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.calendar.index.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
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
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail((int) $id);

            $document->scheduled_at = new \DateTimeImmutable($validated['date']);
            $document->save();

            return AdminResponse::success([
                'id' => $document->id,
                'scheduled_at' => $document->scheduled_at?->format('Y-m-d'),
            ], trans_message('payments.calendar.rescheduled'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.calendar.reschedule.error', [
                'document_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.calendar.reschedule_error'), 500);
        }
    }

    private function getColorForStatus(string $status): string
    {
        return match ($status) {
            'paid' => '#10B981',
            'scheduled' => '#3B82F6',
            'overdue' => '#EF4444',
            'approved' => '#F59E0B',
            default => '#6B7280',
        };
    }
}
