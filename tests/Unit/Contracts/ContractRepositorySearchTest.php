<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Models\Project;
use PHPUnit\Framework\TestCase;

class ContractRepositorySearchTest extends TestCase
{
    public function test_contract_search_uses_existing_project_external_code_column(): void
    {
        $repositorySource = file_get_contents(
            dirname(__DIR__, 3) . '/app/Repositories/ContractRepository.php'
        );

        self::assertIsString($repositorySource);
        self::assertContains('external_code', (new Project())->getFillable());
        self::assertStringContainsString("orWhere('external_code', 'ilike'", $repositorySource);
        self::assertStringNotContainsString("orWhere('code', 'ilike'", $repositorySource);
    }
}
