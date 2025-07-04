<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mail:test', description: 'Отправляет тестовое письмо через текущий SMTP-драйвер.')]
class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Email-адрес получателя}';

    public function handle(): int
    {
        $to = $this->argument('email');

        Mail::raw('Тестовая отправка из ProHelper через Resend SMTP', function ($m) use ($to) {
            $m->to($to)->subject('Проверка SMTP Resend');
        });

        $this->info("Письмо отправлено на {$to}. Проверьте ящик и логи Resend.");
        return self::SUCCESS;
    }
} 