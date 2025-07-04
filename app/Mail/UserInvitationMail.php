<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class UserInvitationMail extends Mailable
{
    public string $email;
    public string $password;
    public string $loginUrl;

    public function __construct(string $email, string $password, string $loginUrl)
    {
        $this->email = $email;
        $this->password = $password;
        $this->loginUrl = $loginUrl;
    }

    public function build()
    {
        return $this
            ->subject('Приглашение в ProHelper')
            ->view('emails.user_invitation')
            ->with([
                'email'     => $this->email,
                'password'  => $this->password,
                'loginUrl'  => $this->loginUrl,
            ]);
    }
} 