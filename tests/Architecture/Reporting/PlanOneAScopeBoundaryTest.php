<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PlanOneAScopeBoundaryTest extends TestCase
{
    private const DISPATCH_BOUNDARY_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExportDispatcher.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportMaterializationDispatcher.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/LaravelReportDispatchIntentPublisher.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php',
    ];

    public function test_plan_one_a_has_only_the_declared_dispatch_boundaries(): void
    {
        self::assertFalse(is_dir($this->reportingRoot().'/Jobs'));
        self::assertSame(self::DISPATCH_BOUNDARY_PATHS, $this->dispatchBoundaryPaths());

        foreach (self::DISPATCH_BOUNDARY_PATHS as $path) {
            self::assertFileExists($this->root().'/'.$path);
        }

        foreach (array_slice(self::DISPATCH_BOUNDARY_PATHS, 0, 3) as $path) {
            $source = $this->source($path);

            self::assertMatchesRegularExpression(
                '/namespace App\\\\BusinessModules\\\\Core\\\\Reporting\\\\Application\\\\Contracts\\\\Execution;/',
                $source,
            );
            self::assertMatchesRegularExpression('/\binterface\s+Report\w+Dispatcher\b/', $source);
            self::assertMatchesRegularExpression('/public function dispatch\s*\([^)]*\): void;/', $source);
        }

        $publisher = $this->source(self::DISPATCH_BOUNDARY_PATHS[3]);
        self::assertMatchesRegularExpression(
            '/namespace App\\\\BusinessModules\\\\Core\\\\Reporting\\\\Infrastructure\\\\Dispatch;/',
            $publisher,
        );
        self::assertMatchesRegularExpression('/\bfinal class LaravelReportDispatchIntentPublisher\b/', $publisher);
        self::assertMatchesRegularExpression('/->dispatch\s*\(/', $publisher);

        $queueAdapter = $this->source(self::DISPATCH_BOUNDARY_PATHS[4]);
        self::assertMatchesRegularExpression(
            '/namespace App\\\\BusinessModules\\\\Core\\\\Reporting\\\\Infrastructure\\\\Queue;/',
            $queueAdapter,
        );
        self::assertMatchesRegularExpression(
            '/\bfinal class LaravelReportMaterializationDispatcher implements ReportMaterializationDispatcher\b/',
            $queueAdapter,
        );
        self::assertMatchesRegularExpression('/MaterializeReportRunJob::dispatch\s*\(/', $queueAdapter);
    }

    public function test_reporting_jobs_are_consumers_and_not_undeclared_dispatch_boundaries(): void
    {
        $jobsRoot = $this->reportingRoot().'/Infrastructure/Jobs';
        self::assertDirectoryExists($jobsRoot);

        foreach ($this->phpSources() as $path => $source) {
            if (in_array($path, self::DISPATCH_BOUNDARY_PATHS, true)) {
                continue;
            }

            self::assertSame([], $this->dispatchLeakViolations($source), $path);
        }
    }

    public function test_job_consumer_local_dispatch_mutations_are_rejected(): void
    {
        $source = <<<'PHP'
<?php

final class MutatedReportJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function dispatch(): void
    {
        dispatch(new OtherReportJob());
    }
}
PHP;

        self::assertContains('dispatch_declaration', $this->dispatchLeakViolations($source));
        self::assertContains('bare_dispatch_call', $this->dispatchLeakViolations($source));
        self::assertContains('job_construction', $this->dispatchLeakViolations($source));
    }

    public function test_plan_one_a_contains_no_persistence_or_storage_implementation(): void
    {
        self::assertFalse(
            is_dir($this->reportingRoot().'/Persistence')
                || is_dir($this->reportingRoot().'/Storage'),
        );
    }

    public function test_plan_one_a_contains_no_catalog_loader_or_manifest_yaml(): void
    {
        self::assertFalse($this->reportingSourceContains('yaml_parse') || $this->reportingSourceContains('ManifestLoader'));
    }

    public function test_plan_one_a_contains_no_provider_formula_implementation(): void
    {
        self::assertFalse($this->reportingSourceContains('FormulaProvider'));
    }

    public function test_plan_one_a_contains_no_migration_owned_by_reporting(): void
    {
        self::assertSame([], glob($this->root().'/database/migrations/*reporting*') ?: []);
    }

    public function test_plan_one_a_contains_no_ui_code(): void
    {
        self::assertFalse(
            $this->reportingSourceContains('<script')
                || $this->reportingSourceContains('React.')
                || $this->reportingSourceContains('flutter'),
        );
    }

    private function reportingSourceContains(string $needle): bool
    {
        foreach ($this->phpSources() as $source) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function dispatchBoundaryPaths(): array
    {
        $paths = [];

        foreach ($this->phpSources() as $path => $source) {
            $isDispatcherInterface = preg_match('/\binterface\s+Report\w+Dispatcher\b/', $source) === 1
                && preg_match('/public function dispatch\s*\([^)]*\): void;/', $source) === 1;
            $isDispatchPublisher = preg_match('/\bclass\s+\w*Publisher\b/', $source) === 1
                && preg_match('/->dispatch\s*\(/', $source) === 1;
            $isQueueAdapter = preg_match('/\bclass\s+\w*Dispatcher\b/', $source) === 1
                && preg_match('/::dispatch\s*\(/', $source) === 1;

            if ($isDispatcherInterface || $isDispatchPublisher || $isQueueAdapter) {
                $paths[] = $path;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return array<string, string>
     */
    private function phpSources(): array
    {
        $sources = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->reportingRoot(), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());
            $root = rtrim(str_replace('\\', '/', $this->root()), '/').'/';
            self::assertStringStartsWith($root, $absolutePath);
            $relativePath = substr($absolutePath, strlen($root));
            self::assertIsString($relativePath);

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $sources[$relativePath] = $source;
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root().'/'.$path);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @return list<string>
     */
    private function dispatchLeakViolations(string $source): array
    {
        $patterns = [
            'dispatch_declaration' => '/\bfunction\s+dispatch\s*\(/',
            'member_dispatch_call' => '/(?:->|::)\s*dispatch\s*\(/',
            'bare_dispatch_call' => '/(?<!->)(?<!::)\bdispatch\s*\(/',
            'queue_facade_import' => '/use Illuminate\\\\Support\\\\Facades\\\\(?:Bus|Queue);/',
            'queue_facade_call' => '/\\\\?Illuminate\\\\Support\\\\Facades\\\\(?:Bus|Queue)::/',
            'job_construction' => '/\bnew\s+(?:\\\\?[A-Z]\w*\\\\)*[A-Z]\w*Job\s*\(/',
        ];
        $violations = [];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $violations[] = $name;
            }
        }

        return $violations;
    }

    private function reportingRoot(): string
    {
        return $this->root().'/app/BusinessModules/Core/Reporting';
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
