<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Carbon\Carbon;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use PHPUnit\Framework\TestCase;

final class EmailRenderingTest extends TestCase
{
    public function test_all_emails_render_without_legacy_branding_and_preserve_actions(): void
    {
        $root = dirname(__DIR__, 3);
        $output = $root.'/storage/framework/testing/mail-preview';
        if (!is_dir($output)) {
            mkdir($output, 0777, true);
        }
        $app = new Application($root);
        $app->useLangPath($root.'/lang');
        $app->instance('config', new Repository([
            'app' => ['name' => 'Prohelper', 'url' => 'https://example.test', 'locale' => 'ru', 'fallback_locale' => 'ru'],
            'view' => ['paths' => [$root.'/resources/views'], 'compiled' => $output],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, TranslationServiceProvider::class, ViewServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $view = $app['view'];
        $view->addNamespace('notifications', $root.'/resources/views/vendor/notifications');
        $markdown = new Markdown($view, ['paths' => [$root.'/resources/views/vendor/mail']]);
        $url = 'https://example.test/action?token=fixture-token&email=reader%40example.test';
        $date = Carbon::parse('2026-09-15 12:30:00');
        $name = '<script>alert(1)</script>';
        $user = (object) ['name' => $name];
        $organization = (object) ['name' => 'Тестовая организация'];
        $item = (object) ['name' => 'Материал', 'material_name' => 'Материал', 'quantity' => 2, 'unit' => 'шт.'];
        $contact = (object) array_fill_keys(['name', 'email', 'phone', 'company', 'company_role', 'company_size', 'subject', 'page_source', 'message', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], 'Тест');
        $contact->message = $name;
        $invitation = (object) ['organization' => $organization, 'expires_at' => $date];
        $order = (object) ['order_number' => 'З-001', 'order_date' => $date, 'total_amount' => 1200, 'currency' => 'RUB', 'delivery_date' => $date, 'notes' => $name];
        $cases = [
            'emails.user_invitation' => ['isTokenInvitation' => true, 'invitation' => $invitation, 'acceptUrl' => $url],
            'emails.support_request' => ['senderName' => $name, 'senderEmail' => 'reader@example.test', 'userId' => 1, 'subjectText' => 'Вопрос', 'messageText' => $name],
            'emails.support_ticket_reply' => ['recipientName' => $name, 'requestSubject' => 'Вопрос', 'bodyText' => $name, 'operatorName' => 'Поддержка'],
            'emails.contractor-invitation' => ['organizationName' => $organization->name, 'invitedBy' => $name, 'message' => $name, 'expiresAt' => $date, 'invitationUrl' => $url],
            'emails.scheduled_report' => ['report_name' => 'Отчёт', 'report_description' => $name, 'generated_at' => '15.09.2026 12:30'],
            'emails.new_device_login' => ['user' => $user, 'session' => (object) ['first_seen_at' => $date, 'device_name' => $name, 'ip_address' => '192.0.2.1']],
            'emails.public-contact-form' => ['contactForm' => $contact],
            'procurement.emails.purchase-order-sent' => ['order' => $order, 'supplier' => $organization, 'organization' => $organization, 'items' => [$item]],
            'procurement.emails.supplier-request-link' => ['supplierName' => $name, 'organization' => null, 'supplierRequest' => (object) ['request_number' => 'ЗП-001', 'public_token_expires_at' => $date], 'purchaseRequest' => null, 'lines' => collect([$item]), 'publicUrl' => $url],
        ];
        foreach ($cases as $template => $data) {
            $html = $view->make($template, $data)->render();
            $this->checkHtml($html);
            foreach (['acceptUrl', 'invitationUrl', 'publicUrl'] as $key) {
                if (isset($data[$key])) {
                    self::assertStringContainsString(e($url), $html);
                }
            }
            file_put_contents($output.'/'.str_replace('.', '-', $template).'.html', $html);
        }
        foreach (['https://example.test/login', 'https://disk.yandex.test/download'] as $loginUrl) {
            $html = $view->make('emails.user_invitation', ['isTokenInvitation' => false, 'email' => 'reader@example.test', 'password' => 'fixture-only', 'loginUrl' => $loginUrl])->render();
            $this->checkHtml($html);
            self::assertStringContainsString($loginUrl, $html);
            self::assertStringContainsString('fixture-only', $html);
        }
        foreach ([
            'emails.email_verification' => ['user' => $user, 'verificationUrl' => $url],
            'emails.user_welcome' => ['user' => $user],
            'emails.trial_expired' => ['moduleName' => 'Склад', 'organizationName' => 'Тест', 'lkUrl' => 'https://example.test'],
        ] as $template => $data) {
            $html = (string) $markdown->render($template, $data);
            $this->checkHtml($html);
            $this->checkText((string) $markdown->renderText($template, $data));
            file_put_contents($output.'/'.str_replace('.', '-', $template).'.html', $html);
        }
        foreach (['info', 'success', 'error'] as $level) {
            $message = (new MailMessage())->level($level)->line('Запрос на смену пароля.')->action('Сменить пароль', $url);
            $html = (string) $markdown->render('notifications::email', $message->data());
            $this->checkHtml($html);
            self::assertStringContainsString(e($url), $html);
            self::assertStringContainsString('Если кнопка', $html);
            $text = (string) $markdown->renderText('notifications::email', $message->data());
            $this->checkText($text);
            self::assertStringContainsString($url, $text);
            file_put_contents($output.'/password-reset-'.$level.'.html', $html);
        }
        $message = (new MailMessage())->line('Уведомление без кнопки.')->salutation('Особая подпись');
        $html = (string) $markdown->render('notifications::email', $message->data());
        $this->checkHtml($html);
        self::assertStringContainsString('Особая подпись', $html);
        self::assertStringNotContainsString('Если кнопка', $html);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        $app->flush();
    }

    private function checkHtml(string $html): void
    {
        $this->checkText($html);
        self::assertStringContainsString('name="viewport"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('mail.greeting', $html);
        self::assertStringNotContainsString('mail.fallback', $html);
    }

    private function checkText(string $text): void
    {
        self::assertStringContainsString('МОСТ', $text);
        self::assertDoesNotMatchRegularExpression('/prohelper|Regards,|All rights reserved|having trouble clicking/i', $text);
    }
}
