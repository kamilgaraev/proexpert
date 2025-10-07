<?php

namespace Database\Seeders\Notifications;

use Illuminate\Database\Seeder;
use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;

class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'type' => 'contract_status_changed',
                'channel' => 'email',
                'name' => 'Изменение статуса контракта (Email)',
                'subject' => 'Статус контракта {{contract.number}} изменен',
                'content' => $this->getEmailContractStatusChanged(),
                'variables' => ['contract', 'old_status', 'new_status', 'user', 'organization'],
                'is_default' => true,
            ],
            [
                'type' => 'contract_status_changed',
                'channel' => 'telegram',
                'name' => 'Изменение статуса контракта (Telegram)',
                'subject' => null,
                'content' => $this->getTelegramContractStatusChanged(),
                'variables' => ['contract', 'old_status', 'new_status', 'user'],
                'is_default' => true,
            ],
            [
                'type' => 'contract_status_changed',
                'channel' => 'in_app',
                'name' => 'Изменение статуса контракта (In-App)',
                'subject' => 'Статус контракта изменен',
                'content' => $this->getInAppContractStatusChanged(),
                'variables' => ['contract', 'old_status', 'new_status'],
                'is_default' => true,
            ],
            
            [
                'type' => 'contractor_invitation',
                'channel' => 'email',
                'name' => 'Приглашение подрядчика (Email)',
                'subject' => 'Приглашение к сотрудничеству от {{organization.name}}',
                'content' => $this->getEmailContractorInvitation(),
                'variables' => ['invitation', 'organization', 'invited_by', 'user'],
                'is_default' => true,
            ],
            [
                'type' => 'contractor_invitation',
                'channel' => 'telegram',
                'name' => 'Приглашение подрядчика (Telegram)',
                'subject' => null,
                'content' => $this->getTelegramContractorInvitation(),
                'variables' => ['invitation', 'organization', 'invited_by'],
                'is_default' => true,
            ],
            
            [
                'type' => 'dashboard_alert',
                'channel' => 'email',
                'name' => 'Dashboard Alert (Email)',
                'subject' => 'Алерт: {{alert.name}}',
                'content' => $this->getEmailDashboardAlert(),
                'variables' => ['alert', 'user', 'organization'],
                'is_default' => true,
            ],
            [
                'type' => 'dashboard_alert',
                'channel' => 'telegram',
                'name' => 'Dashboard Alert (Telegram)',
                'subject' => null,
                'content' => $this->getTelegramDashboardAlert(),
                'variables' => ['alert'],
                'is_default' => true,
            ],
            [
                'type' => 'dashboard_alert',
                'channel' => 'in_app',
                'name' => 'Dashboard Alert (In-App)',
                'subject' => '{{alert.name}}',
                'content' => $this->getInAppDashboardAlert(),
                'variables' => ['alert'],
                'is_default' => true,
            ],
        ];

        foreach ($templates as $templateData) {
            NotificationTemplate::create($templateData);
        }
    }

    private function getEmailContractStatusChanged(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F46E5; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 20px; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; }
        .button { display: inline-block; background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 10px 0; }
        .status { font-weight: bold; padding: 4px 12px; border-radius: 4px; display: inline-block; }
        .status-old { background: #fee2e2; color: #991b1b; }
        .status-new { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Изменение статуса контракта</h1>
        </div>
        <div class="content">
            <p>Здравствуйте, {{user.name}}!</p>
            
            <p>Статус контракта <strong>{{contract.number}}</strong> был изменен:</p>
            
            <p>
                <span class="status status-old">{{old_status}}</span>
                →
                <span class="status status-new">{{new_status}}</span>
            </p>
            
            <p><strong>Детали контракта:</strong></p>
            <ul>
                <li>Номер: {{contract.number}}</li>
                <li>Проект: {{contract.project_name}}</li>
                <li>Сумма: {{contract.total_amount}} руб.</li>
                <li>Выполнено: {{contract.completion_percentage}}%</li>
            </ul>
            
            <a href="{{contract.url}}" class="button">Посмотреть контракт</a>
        </div>
        <div class="footer">
            <p>{{organization.name}}</p>
            <p>Это автоматическое уведомление. Пожалуйста, не отвечайте на это письмо.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getTelegramContractStatusChanged(): string
    {
        return <<<'MARKDOWN'
🔔 *Изменение статуса контракта*

📋 Контракт: `{{contract.number}}`
📊 Проект: {{contract.project_name}}

Статус изменен:
❌ Было: {{old_status}}
✅ Стало: {{new_status}}

💰 Сумма контракта: {{contract.total_amount}} руб.
📈 Выполнено: {{contract.completion_percentage}}%

[Посмотреть детали]({{contract.url}})
MARKDOWN;
    }

    private function getInAppContractStatusChanged(): string
    {
        return json_encode([
            'title' => 'Статус контракта {{contract.number}} изменен',
            'message' => 'Статус изменен с "{{old_status}}" на "{{new_status}}"',
            'icon' => 'contract',
            'color' => 'blue',
            'action' => [
                'label' => 'Посмотреть',
                'url' => '{{contract.url}}',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function getEmailContractorInvitation(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #059669; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 20px; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; }
        .button { display: inline-block; background: #059669; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 10px 0; }
        .highlight { background: #fef3c7; padding: 15px; border-left: 4px solid #f59e0b; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Приглашение к сотрудничеству</h1>
        </div>
        <div class="content">
            <p>Здравствуйте!</p>
            
            <p>Компания <strong>{{organization.name}}</strong> приглашает вас к сотрудничеству в качестве подрядчика.</p>
            
            <div class="highlight">
                <p><strong>Сообщение от {{invited_by.name}}:</strong></p>
                <p>{{invitation.message}}</p>
            </div>
            
            <p><strong>Детали приглашения:</strong></p>
            <ul>
                <li>От кого: {{invited_by.name}} ({{invited_by.position}})</li>
                <li>Организация: {{organization.name}}</li>
                <li>Действительно до: {{invitation.expires_at}}</li>
            </ul>
            
            <a href="{{invitation.url}}" class="button">Принять приглашение</a>
            
            <p><small>Если вы не ожидали это приглашение, просто проигнорируйте это письмо.</small></p>
        </div>
        <div class="footer">
            <p>{{organization.name}}</p>
            <p>Это автоматическое уведомление. Пожалуйста, не отвечайте на это письмо.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getTelegramContractorInvitation(): string
    {
        return <<<'MARKDOWN'
🤝 *Приглашение к сотрудничеству*

Компания *{{organization.name}}* приглашает вас к сотрудничеству в качестве подрядчика.

👤 От кого: {{invited_by.name}}
🏢 Организация: {{organization.name}}
⏳ Действительно до: {{invitation.expires_at}}

💬 Сообщение:
{{invitation.message}}

[Принять приглашение]({{invitation.url}})
MARKDOWN;
    }

    private function getEmailDashboardAlert(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc2626; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 20px; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; }
        .button { display: inline-block; background: #dc2626; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin: 10px 0; }
        .alert-box { background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; margin: 15px 0; }
        .priority { font-weight: bold; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Alert: {{alert.name}}</h1>
        </div>
        <div class="content">
            <p>Здравствуйте, {{user.name}}!</p>
            
            <div class="alert-box">
                <p class="priority">Приоритет: {{alert.priority}}</p>
                <p><strong>{{alert.description}}</strong></p>
            </div>
            
            <p><strong>Детали:</strong></p>
            <ul>
                <li>Тип: {{alert.alert_type}}</li>
                <li>Объект: {{alert.target_entity}}</li>
                <li>Порог: {{alert.threshold_value}} {{alert.threshold_unit}}</li>
                <li>Время срабатывания: {{alert.triggered_at}}</li>
            </ul>
            
            <a href="{{alert.url}}" class="button">Посмотреть дашборд</a>
        </div>
        <div class="footer">
            <p>{{organization.name}}</p>
            <p>Это автоматическое уведомление. Пожалуйста, не отвечайте на это письмо.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getTelegramDashboardAlert(): string
    {
        return <<<'MARKDOWN'
⚠️ *ALERT: {{alert.name}}*

🔴 Приоритет: *{{alert.priority}}*

📋 {{alert.description}}

Детали:
• Тип: {{alert.alert_type}}
• Объект: {{alert.target_entity}}
• Порог: {{alert.threshold_value}} {{alert.threshold_unit}}
• Время: {{alert.triggered_at}}

[Посмотреть дашборд]({{alert.url}})
MARKDOWN;
    }

    private function getInAppDashboardAlert(): string
    {
        return json_encode([
            'title' => '{{alert.name}}',
            'message' => '{{alert.description}}',
            'icon' => 'alert',
            'color' => 'red',
            'priority' => '{{alert.priority}}',
            'action' => [
                'label' => 'Посмотреть',
                'url' => '{{alert.url}}',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

