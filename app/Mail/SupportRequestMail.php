<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SupportRequestMail extends Mailable
{
    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $subjectText,
        public readonly string $messageText,
        public readonly ?int $userId = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(trans_message('support.email.subject', ['subject' => $this->subjectText]))
            ->replyTo($this->senderEmail, $this->senderName)
            ->view('emails.support_request')
            ->with([
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'subjectText' => $this->subjectText,
                'messageText' => $this->messageText,
                'userId' => $this->userId,
            ]);
    }
}
