<?php

declare(strict_types=1);

namespace App\Services\Filament;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\BusinessModules\Features\Notifications\Services\TemplateRenderer;
use App\Models\SystemAdmin;
use App\Notifications\SystemAdminTemplatePreviewNotification;

class NotificationTemplateManagementService
{
    public function __construct(
        private readonly TemplateRenderer $templateRenderer,
    ) {
    }

    public function preview(NotificationTemplate $template, SystemAdmin $systemAdmin, array $sampleData = []): array
    {
        $data = array_replace_recursive($this->sampleData($systemAdmin), $sampleData);
        $subject = $template->subject
            ? $this->templateRenderer->renderString((string) $template->subject, $data)
            : $template->name;

        return [
            'template_name' => $template->name,
            'type' => $template->type,
            'channel' => $template->channel,
            'subject' => $subject,
            'content' => $this->templateRenderer->render($template, $data),
            'sample_data' => $data,
        ];
    }

    public function sendTest(NotificationTemplate $template, SystemAdmin $systemAdmin): void
    {
        $preview = $this->preview($template, $systemAdmin);

        $systemAdmin->notify(new SystemAdminTemplatePreviewNotification(
            subject: (string) $preview['subject'],
            content: (string) $preview['content'],
            templateName: (string) $preview['template_name'],
        ));
    }

    private function sampleData(SystemAdmin $systemAdmin): array
    {
        return [
            'system_admin' => [
                'id' => $systemAdmin->id,
                'name' => $systemAdmin->name,
                'email' => $systemAdmin->email,
                'role' => $systemAdmin->getRoleName(),
            ],
            'user' => [
                'id' => 1,
                'name' => $systemAdmin->name,
                'email' => $systemAdmin->email,
            ],
            'organization' => [
                'id' => 1,
                'name' => 'ProHelper Demo',
            ],
            'project' => [
                'id' => 1,
                'name' => 'Demo project',
                'number' => 'PRJ-001',
            ],
            'system' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'support_email' => config('mail.from.address'),
            ],
        ];
    }
}
