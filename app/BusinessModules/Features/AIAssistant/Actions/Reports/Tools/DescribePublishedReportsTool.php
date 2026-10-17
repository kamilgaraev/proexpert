<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportCatalog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class DescribePublishedReportsTool implements AIToolInterface
{
    public function __construct(private AssistantPublishedReportCatalog $catalog) {}

    public function getName(): string
    {
        return 'describe_published_reports';
    }

    public function getDescription(): string
    {
        return trans_message('ai_published_reports.catalog_description');
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'report_code' => ['type' => 'string', 'maxLength' => 64],
        ]];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        return $this->catalog->read($user, (int) $organization->id, $arguments['report_code'] ?? null);
    }
}
