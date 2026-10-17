<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportTasks;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSubscriptionSchema;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class GeneratePublishedReportTool implements AIToolInterface
{
    public function __construct(private AssistantPublishedReportTasks $tasks) {}

    public function getName(): string
    {
        return 'generate_published_report';
    }

    public function getDescription(): string
    {
        return trans_message('ai_published_reports.generate_description');
    }

    public function getParametersSchema(): array
    {
        $schema = AssistantReportSubscriptionSchema::create();
        unset($schema['properties']['name']);
        $schema['properties']['title'] = ['type' => 'string', 'maxLength' => 120];
        foreach (['frequency', 'weekday', 'day_of_month', 'local_time'] as $field) {
            unset($schema['properties'][$field]);
        }
        $schema['properties']['report_code']['description'] = trans_message('ai_published_reports.code_description');
        $schema['required'] = ['report_code', 'scope', 'columns', 'timezone', 'period_mode', 'format'];

        return $schema;
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $this->tasks->create($arguments, $user, (int) $organization->id);
    }
}
