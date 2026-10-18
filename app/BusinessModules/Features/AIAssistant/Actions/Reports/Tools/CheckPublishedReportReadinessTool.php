<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportTasks;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class CheckPublishedReportReadinessTool implements AIToolInterface
{
    public function __construct(private AssistantPublishedReportTasks $tasks) {}

    public function getName(): string
    {
        return 'check_published_report_readiness';
    }

    public function getDescription(): string
    {
        return trans_message('ai_published_reports.check_description');
    }

    public function getParametersSchema(): array
    {
        return GeneratePublishedReportTool::schema();
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $this->tasks->inspect($arguments, $user, (int) $organization->id);
    }
}
