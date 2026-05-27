<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SupportTicketReplyMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $requestSubject,
        public readonly string $subjectText,
        public readonly string $bodyText,
        public readonly string $operatorName,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject(trans_message('support.email.reply_subject', ['subject' => $this->subjectText]))
            ->view('emails.support_ticket_reply')
            ->with([
                'recipientName' => $this->recipientName,
                'requestSubject' => $this->requestSubject,
                'bodyText' => $this->bodyText,
                'operatorName' => $this->operatorName,
            ]);
    }
}
