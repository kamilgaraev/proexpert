<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use function trans_message;

class UserInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public ?UserInvitation $invitation;
    public string $email;
    public ?string $password;
    public string $loginUrl;
    public string $acceptUrl;

    public function __construct(
        UserInvitation|string $invitationOrEmail,
        ?string $acceptUrlOrPassword = null,
        ?string $loginUrl = null
    ) {
        if ($invitationOrEmail instanceof UserInvitation) {
            $this->invitation = $invitationOrEmail;
            $this->email = $invitationOrEmail->email;
            $this->password = null;
            $this->acceptUrl = $acceptUrlOrPassword ?? $invitationOrEmail->invitation_url;
            $this->loginUrl = $this->acceptUrl;

            return;
        }

        $this->invitation = null;
        $this->email = $invitationOrEmail;
        $this->password = $acceptUrlOrPassword;
        $this->loginUrl = $loginUrl ?? (string) config('app.frontend_url', config('app.url'));
        $this->acceptUrl = $this->loginUrl;
    }

    public function build(): self
    {
        return $this
            ->subject(trans_message('user_invitations.email.subject'))
            ->view('emails.user_invitation')
            ->with([
                'invitation' => $this->invitation,
                'email' => $this->email,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
                'acceptUrl' => $this->acceptUrl,
                'isTokenInvitation' => $this->invitation !== null,
            ]);
    }
}
