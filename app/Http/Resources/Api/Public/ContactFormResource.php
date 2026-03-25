<?php

namespace App\Http\Resources\Api\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ContactForm */
class ContactFormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'company_role' => $this->company_role,
            'company_size' => $this->company_size,
            'subject' => $this->subject,
            'message' => $this->message,
            'consent_to_personal_data' => $this->consent_to_personal_data,
            'consent_version' => $this->consent_version,
            'page_source' => $this->page_source,
            'utm' => [
                'source' => $this->utm_source,
                'medium' => $this->utm_medium,
                'campaign' => $this->utm_campaign,
                'term' => $this->utm_term,
                'content' => $this->utm_content,
            ],
            'status' => $this->status,
            'is_processed' => $this->is_processed,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'processed_at' => $this->processed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
