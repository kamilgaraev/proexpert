<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentCalendarController extends Controller
{
    /**
     * Get calendar events
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $start = $request->input('start'); // ISO date
        $end = $request->input('end'); // ISO date

        $documents = PaymentDocument::forOrganization($organizationId)
            ->whereBetween('due_date', [$start, $end])
            ->orWhereBetween('scheduled_at', [$start, $end])
            ->get();

        $events = $documents->map(function (PaymentDocument $doc) {
            $isScheduled = !is_null($doc->scheduled_at);
            $date = $isScheduled ? $doc->scheduled_at : $doc->due_date;
            
            return [
                'id' => $doc->id,
                'title' => $doc->getPayerName() . ' - ' . $doc->amount,
                'start' => $date->format('Y-m-d'),
                'backgroundColor' => $this->getColorForStatus($doc->status->value),
                'extendedProps' => [
                    'amount' => $doc->amount,
                    'status' => $doc->status->value,
                    'type' => $doc->document_type->value,
                ]
            ];
        });

        return response()->json($events);
    }

    /**
     * Reschedule a payment (Drag & Drop handler)
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $document = PaymentDocument::forOrganization($organizationId)->findOrFail($id);
        
        $date = $request->input('date'); // YYYY-MM-DD
        
        // Update scheduled date
        $document->scheduled_at = new \DateTime($date);
        $document->save();

        return response()->json(['success' => true]);
    }

    private function getColorForStatus(string $status): string
    {
        return match($status) {
            'paid' => '#10B981', // Green
            'scheduled' => '#3B82F6', // Blue
            'overdue' => '#EF4444', // Red
            'approved' => '#F59E0B', // Orange
            default => '#6B7280', // Gray
        };
    }
}

