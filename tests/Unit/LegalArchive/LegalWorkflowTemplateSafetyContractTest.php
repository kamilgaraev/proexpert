<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalWorkflowTemplateSafetyContractTest extends TestCase
{
    public function test_template_creation_validates_user_assignees_inside_the_organization(): void
    {
        $request = $this->source('app/Http/Requests/Api/V1/Admin/LegalArchive/StoreLegalArchiveWorkflowTemplateRequest.php');

        self::assertStringContainsString('public function withValidator(Validator $validator): void', $request);
        self::assertStringContainsString("->where('organization_id', \$organizationId)", $request);
        self::assertStringContainsString("->where('is_active', true)", $request);
        self::assertStringContainsString('RoleScanner::class', $request);
        self::assertStringContainsString('workflow_assignee_invalid', $request);
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        self::assertIsString($source);

        return $source;
    }
}
