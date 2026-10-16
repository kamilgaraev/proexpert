<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantReportArtifactOwnershipTest extends TestCase
{
    public function test_delivers_own_private_report_and_rejects_other_owners_and_organizations(): void
    {
        $reflection = new ReflectionClass(AIAssistantService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('filterAgentArtifactsForOrganization');
        $paths = ['org-38/personal-files/user-39/report.pdf',
            'org-38/personal-files/user-390/report.pdf', 'org-38/personal-files/user-40/report.pdf',
            'org-380/personal-files/user-39/report.pdf', 'org-38/reports/legacy.pdf'];
        $artifacts = array_map(static fn (string $path): array => ['storage_disk' => 's3', 'storage_path' => $path], $paths);

        $this->assertSame([$artifacts[0], $artifacts[4]], $method->invoke($service, 38, $artifacts, 39));
    }
}
