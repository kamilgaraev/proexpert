<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ProjectParticipantInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use function trans_message;

class ProjectParticipantInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ProjectParticipantInvitation $invitation,
        public readonly string $acceptUrl,
    ) {}

    public function build(): self
    {
        $roleKey = 'project_invitations.roles.'.$this->invitation->role;
        $roleLabel = trans_message($roleKey);
        if ($roleLabel === $roleKey) {
            $roleLabel = $this->invitation->roleEnum()->label();
        }

        return $this
            ->subject(trans_message('project_invitations.email.subject'))
            ->view('emails.project_participant_invitation')
            ->with([
                'invitation' => $this->invitation,
                'acceptUrl' => $this->acceptUrl,
                'projectName' => $this->invitation->project?->name ?? '',
                'organizationName' => $this->invitation->organization?->name ?? '',
                'roleLabel' => $roleLabel,
                'customMessage' => $this->invitation->message,
                'expiresAt' => $this->invitation->expires_at,
            ]);
    }
}
