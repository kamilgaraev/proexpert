<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource для заявки
 */
class SiteRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'assigned_to' => $this->assigned_to,

            // Основные поля
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'priority_color' => $this->priority->color(),
            'request_type' => $this->request_type->value,
            'request_type_label' => $this->request_type->label(),
            'required_date' => $this->required_date?->format('Y-m-d'),
            'notes' => $this->notes,

            // Материалы
            'material_id' => $this->material_id,
            'material_name' => $this->material_name,
            'material_quantity' => $this->material_quantity,
            'material_unit' => $this->material_unit,
            'delivery_address' => $this->delivery_address,
            'delivery_time_from' => $this->delivery_time_from,
            'delivery_time_to' => $this->delivery_time_to,
            'contact_person_name' => $this->contact_person_name,
            'contact_person_phone' => $this->contact_person_phone,

            // Персонал
            'personnel_type' => $this->personnel_type?->value,
            'personnel_type_label' => $this->personnel_type?->label(),
            'personnel_count' => $this->personnel_count,
            'personnel_requirements' => $this->personnel_requirements,
            'hourly_rate' => $this->hourly_rate,
            'work_hours_per_day' => $this->work_hours_per_day,
            'work_start_date' => $this->work_start_date?->format('Y-m-d'),
            'work_end_date' => $this->work_end_date?->format('Y-m-d'),
            'work_location' => $this->work_location,
            'additional_conditions' => $this->additional_conditions,
            'estimated_personnel_cost' => $this->estimated_personnel_cost,

            // Техника
            'equipment_type' => $this->equipment_type,
            'equipment_specs' => $this->equipment_specs,
            'rental_start_date' => $this->rental_start_date?->format('Y-m-d'),
            'rental_end_date' => $this->rental_end_date?->format('Y-m-d'),
            'rental_hours_per_day' => $this->rental_hours_per_day,
            'with_operator' => $this->with_operator,
            'equipment_location' => $this->equipment_location,

            // Метаданные
            'metadata' => $this->metadata,
            'template_id' => $this->template_id,
            'site_request_group_id' => $this->site_request_group_id,

            // Вычисляемые поля
            'is_overdue' => $this->is_overdue,
            'days_until_required' => $this->days_until_required,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'has_calendar_event' => $this->hasCalendarEvent(),
            'can_create_payment' => $this->canCreatePayment(),
            'has_payment' => $this->hasPaymentDocument(),

            // Связи
            'project' => $this->whenLoaded('project', fn() => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
            ] : null),
            'files' => $this->whenLoaded('files', fn() => $this->files->map(fn($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'url' => $file->url,
                'size' => $file->size,
                'mime_type' => $file->mime_type,
            ])),
            'calendar_event' => $this->whenLoaded('calendarEvent', fn() => $this->calendarEvent ? [
                'id' => $this->calendarEvent->id,
                'title' => $this->calendarEvent->title,
                'start_date' => $this->calendarEvent->start_date->format('Y-m-d'),
                'end_date' => $this->calendarEvent->end_date?->format('Y-m-d'),
                'color' => $this->calendarEvent->color,
            ] : null),
            'payment_documents' => $this->whenLoaded('paymentDocuments', fn() => $this->paymentDocuments->map(fn($payment) => [
                'id' => $payment->id,
                'document_number' => $payment->document_number,
                'document_type' => $payment->document_type->value,
                'document_type_label' => $payment->document_type->label(),
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'due_date' => $payment->due_date?->format('Y-m-d'),
                'created_at' => $payment->created_at->toIso8601String(),
            ])),
            'payment_document' => $this->whenLoaded('paymentDocument', fn() => $this->paymentDocument ? [
                'id' => $this->paymentDocument->id,
                'document_number' => $this->paymentDocument->document_number,
                'document_type' => $this->paymentDocument->document_type->value,
                'document_type_label' => $this->paymentDocument->document_type->label(),
                'amount' => (float) $this->paymentDocument->amount,
                'currency' => $this->paymentDocument->currency,
                'status' => $this->paymentDocument->status->value,
                'status_label' => $this->paymentDocument->status->label(),
                'due_date' => $this->paymentDocument->due_date?->format('Y-m-d'),
                'created_at' => $this->paymentDocument->created_at->toIso8601String(),
            ] : null),

            'group' => $this->whenLoaded('group', fn() => $this->group ? [
                'id' => $this->group->id,
                'title' => $this->group->title,
                'status' => $this->group->status,
                'status_label' => $this->group->status->label(),
                'status_color' => $this->group->status->color(),
            ] : null),

            // Даты
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

