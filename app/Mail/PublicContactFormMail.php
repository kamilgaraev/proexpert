<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactForm;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublicContactFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactForm $contactForm,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка с сайта ProHelper: ' . $this->contactForm->subject,
            replyTo: [
                new Address($this->contactForm->email, $this->contactForm->name),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.public-contact-form',
            with: [
                'contactForm' => $this->contactForm,
            ],
        );
    }
}
