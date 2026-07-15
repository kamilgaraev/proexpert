<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class EnterpriseInquiryData
{
    /**
     * @param  array<int, string>  $needs
     */
    public function __construct(
        public string $clientRequestId,
        public string $contactPhone,
        public string $companySize,
        public string $preferredContact,
        public array $needs,
        public ?string $comment,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            clientRequestId: $validated['client_request_id'],
            contactPhone: $validated['contact_phone'],
            companySize: $validated['company_size'],
            preferredContact: $validated['preferred_contact'],
            needs: array_values($validated['needs']),
            comment: $validated['comment'] ?? null,
        );
    }
}
