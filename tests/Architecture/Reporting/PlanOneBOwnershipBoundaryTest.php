<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class PlanOneBOwnershipBoundaryTest extends TestCase
{
    private const FORBIDDEN_PLAN_ONE_B_DECLARATIONS = [
        'ReportDataProvider',
        'ReportRowQuery',
        'ReportDrillDownProvider',
        'ReportDefinition',
        'PublishedReportDefinition',
        'CandidateReportDefinition',
        'ReportDefinitionBinding',
        'ReportDefinitionBindingMap',
        'ReportQuery',
        'ReportPage',
        'ReportRun',
        'ReportExport',
        'ReportCursor',
        'ReportDownloadLink',
        'CreateReportRunData',
        'CreateReportExportData',
    ];

    public function test_plan_one_b_does_not_redeclare_plan_one_a_owned_symbols(): void
    {
        self::assertSame([], $this->duplicatesInRepository($this->root()));
    }

    private function duplicatesInRepository(string $repository): array
    {
        $duplicates = [];
        foreach ($this->planOneBPhpFiles($repository) as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
                    continue;
                }
                $name = $this->declarationName($tokens, $index + 1);
                if ($name !== null && in_array($name, self::FORBIDDEN_PLAN_ONE_B_DECLARATIONS, true)) {
                    $duplicates[] = str_replace('\\', '/', $file).':'.$name;
                }
            }
        }

        return $duplicates;
    }

    public function test_duplicate_blacklist_is_exact_and_unique(): void
    {
        self::assertCount(16, self::FORBIDDEN_PLAN_ONE_B_DECLARATIONS);
        self::assertSame(self::FORBIDDEN_PLAN_ONE_B_DECLARATIONS, array_values(array_unique(self::FORBIDDEN_PLAN_ONE_B_DECLARATIONS)));
    }

    public function test_rejects_forbidden_duplicate_in_application_contracts_execution(): void
    {
        $repository = sys_get_temp_dir().'/most-plan1b-ownership-'.bin2hex(random_bytes(8));
        $directory = $repository.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution';
        mkdir($directory, 0777, true);
        file_put_contents(
            $directory.'/ReportDataProvider.php',
            "<?php\n\nnamespace App\\BusinessModules\\Core\\Reporting\\Application\\Contracts\\Execution;\n\ninterface ReportDataProvider {}\n",
        );

        try {
            self::assertSame(
                [str_replace('\\', '/', $directory.'/ReportDataProvider.php').':ReportDataProvider'],
                $this->duplicatesInRepository($repository),
            );
        } finally {
            unlink($directory.'/ReportDataProvider.php');
            for ($path = $directory; $path !== $repository; $path = dirname($path)) {
                rmdir($path);
            }
            rmdir($repository);
        }
    }

    private function planOneBPhpFiles(string $repository): array
    {
        $reporting = $repository.'/app/BusinessModules/Core/Reporting';
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reporting)),
            '/\\.php$/',
        );
        $files = [];
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/Application/Evidence/')
                || str_contains($path, '/Application/Contracts/Execution/')
                || str_contains($path, '/Application/Execution/')
                || str_contains($path, '/Application/Actions/Handlers/')
                || str_contains($path, '/Application/Rows/')
                || str_contains($path, '/Application/Exports/')
                || str_contains($path, '/Application/Retention/')
                || str_contains($path, '/Application/Audit/')
                || str_contains($path, '/Infrastructure/')
                || str_ends_with($path, '/ReportingExecutionServiceProvider.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function declarationName(array $tokens, int $offset): ?string
    {
        for ($index = $offset, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if ($token === '{' || $token === ';') {
                return null;
            }
        }

        return null;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
