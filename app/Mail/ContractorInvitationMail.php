<?php

namespace App\Mail;

use App\Models\ContractorInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractorInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ContractorInvitation $invitation;

    public function __construct(ContractorInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Приглашение для сотрудничества от ' . $this->invitation->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contractor-invitation',
            with: [
                'invitation' => $this->invitation,
                'organizationName' => $this->invitation->organization->name,
                'invitedBy' => $this->invitation->invitedBy->name,
                'invitationUrl' => $this->invitation->getInvitationUrl(),
                'expiresAt' => $this->invitation->expires_at,
                'message' => $this->invitation->invitation_message,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}