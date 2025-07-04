<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class UserInvitationMail extends Mailable
{
    public string $email;
    public string $password;

    public function __construct(string $email, string $password)
    {
        $this->email = $email;
        $this->password = $password;
    }

    public function build()
    {
        return $this
            ->subject('Приглашение в ProHelper')
            ->view('emails.user_invitation');
    }
} 