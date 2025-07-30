<?php

namespace App\Notifications;

use App\Models\ContractorInvitation;
use App\Mail\ContractorInvitationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ContractorInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ContractorInvitation $invitation;

    public function __construct(ContractorInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): ContractorInvitationMail
    {
        return new ContractorInvitationMail($this->invitation);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contractor_invitation',
            'invitation_id' => $this->invitation->id,
            'organization_name' => $this->invitation->organization->name,
            'invited_by' => $this->invitation->invitedBy->name,
            'message' => $this->invitation->invitation_message,
            'expires_at' => $this->invitation->expires_at->toISOString(),
            'invitation_url' => $this->invitation->getInvitationUrl(),
            'created_at' => $this->invitation->created_at->toISOString(),
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}