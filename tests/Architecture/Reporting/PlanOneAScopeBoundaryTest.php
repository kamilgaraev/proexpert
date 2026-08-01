<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/scripts/reporting/build-plan-1a-evidence.php';

final class PlanOneAScopeBoundaryTest extends TestCase
{
    private static array $sourceCache = [];

    public function test_plan_one_a_has_only_the_phase_declared_committed_dispatch_boundaries(): void
    {
        $phase = $this->phase();
        $sources = $this->phpSources($phase['verified_commit']);

        self::assertFalse($this->hasPathPrefix($sources, 'app/BusinessModules/Core/Reporting/Jobs/'));
        self::assertSame($phase['dispatch_allowlist'], $this->dispatchBoundaryPaths($sources));

        foreach (array_slice($phase['dispatch_allowlist'], 0, 3) as $path) {
            self::assertArrayHasKey($path, $sources);
            self::assertMatchesRegularExpression(
                '/namespace App\\\\BusinessModules\\\\Core\\\\Reporting\\\\Application\\\\Contracts\\\\Execution;/',
                $sources[$path],
            );
            self::assertMatchesRegularExpression('/\binterface\s+Report\w+Dispatcher\b/', $sources[$path]);
            self::assertMatchesRegularExpression('/public function dispatch\s*\([^)]*\): void;/', $sources[$path]);
        }

        $publisherPath = $phase['dispatch_allowlist'][3];
        self::assertMatchesRegularExpression(
            '/\bfinal class LaravelReportDispatchIntentPublisher\b/',
            $sources[$publisherPath],
        );
        self::assertMatchesRegularExpression('/->dispatch\s*\(/', $sources[$publisherPath]);

        $queuePath = 'app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php';
        if ($phase['name'] === \PlanOneAExecutionPhaseAuthority::PRE5) {
            self::assertArrayNotHasKey($queuePath, $sources);
            self::assertFalse($this->hasPathPrefix(
                $sources,
                'app/BusinessModules/Core/Reporting/Infrastructure/Jobs/',
            ));

            return;
        }

        self::assertArrayHasKey($queuePath, $sources);
        self::assertMatchesRegularExpression(
            '/\bfinal class LaravelReportMaterializationDispatcher implements ReportMaterializationDispatcher\b/',
            $sources[$queuePath],
        );
        self::assertMatchesRegularExpression('/MaterializeReportRunJob::dispatch\s*\(/', $sources[$queuePath]);
        self::assertTrue($this->hasPathPrefix(
            $sources,
            'app/BusinessModules/Core/Reporting/Infrastructure/Jobs/',
        ));
    }

    public function test_reporting_jobs_are_consumers_and_not_undeclared_dispatch_boundaries(): void
    {
        $phase = $this->phase();
        foreach ($this->phpSources($phase['verified_commit']) as $path => $source) {
            if (in_array($path, $phase['dispatch_allowlist'], true)) {
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

    public function test_committed_reporting_scope_contains_no_forbidden_plan_one_a_implementation(): void
    {
        $phase = $this->phase();
        $sources = $this->phpSources($phase['verified_commit']);
        self::assertFalse($this->hasPathPrefix($sources, 'app/BusinessModules/Core/Reporting/Persistence/'));
        self::assertFalse($this->hasPathPrefix($sources, 'app/BusinessModules/Core/Reporting/Storage/'));
        self::assertFalse($this->sourceContains($sources, 'yaml_parse'));
        self::assertFalse($this->sourceContains($sources, 'ManifestLoader'));
        self::assertFalse($this->sourceContains($sources, 'FormulaProvider'));
        self::assertFalse($this->sourceContains($sources, '<script'));
        self::assertFalse($this->sourceContains($sources, 'React.'));
        self::assertFalse($this->sourceContains($sources, 'flutter'));
        self::assertSame(
            [],
            array_values(array_filter(
                $this->committedPaths($phase['verified_commit'], 'database/migrations'),
                static fn (string $path): bool => str_contains(basename($path), 'reporting'),
            )),
        );
    }

    public function test_filesystem_overlay_cannot_change_committed_scope_projection(): void
    {
        $phase = $this->phase();
        $before = $this->dispatchBoundaryPaths($this->phpSources($phase['verified_commit']));
        $overlay = dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/OverlayDispatcher.php';
        file_put_contents($overlay, "<?php\nfinal class OverlayDispatcher { public function dispatch(): void {} }\n");
        try {
            self::assertSame(
                $before,
                $this->dispatchBoundaryPaths($this->phpSources($phase['verified_commit'])),
            );
        } finally {
            unlink($overlay);
        }
    }

    public function test_raw_task_four_f_is_historical_red_and_cannot_select_pre5(): void
    {
        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_EXECUTION_PHASE_INVALID');

        \PlanOneAExecutionPhaseAuthority::discover(
            dirname(__DIR__, 3),
            '470fecd5733021421dbc9b36c1d2a410ef27cc42',
        );
    }

    private function phase(): array
    {
        $root = dirname(__DIR__, 3);
        $head = trim($this->git(['rev-parse', 'HEAD']));
        if ($head === '57b9e1b5eb3d646f5d24f78e00165ca9b272e93d') {
            $contract = \PlanOneAExecutionPhaseAuthority::trackedContract();

            return [
                'name' => \PlanOneAExecutionPhaseAuthority::PRE5,
                'verified_commit' => $head,
                'dispatch_allowlist' => $contract['phases'][\PlanOneAExecutionPhaseAuthority::PRE5]['dispatch_allowlist'],
            ];
        }
        $phase = \PlanOneAExecutionPhaseAuthority::discover($root, $head);
        $phase['verified_commit'] = $head;

        return $phase;
    }

    private function dispatchBoundaryPaths(array $sources): array
    {
        $paths = [];
        foreach ($sources as $path => $source) {
            $dispatcher = preg_match('/\binterface\s+Report\w+Dispatcher\b/', $source) === 1
                && preg_match('/public function dispatch\s*\([^)]*\): void;/', $source) === 1;
            $publisher = preg_match('/\bclass\s+\w*Publisher\b/', $source) === 1
                && preg_match('/->dispatch\s*\(/', $source) === 1;
            $queue = preg_match('/\bclass\s+\w*Dispatcher\b/', $source) === 1
                && preg_match('/::dispatch\s*\(/', $source) === 1;
            if ($dispatcher || $publisher || $queue) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function phpSources(string $commit): array
    {
        if (isset(self::$sourceCache[$commit])) {
            return self::$sourceCache[$commit];
        }
        $sources = [];
        foreach ($this->committedPaths($commit, 'app/BusinessModules/Core/Reporting') as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $entry = rtrim($this->git(['--no-replace-objects', 'ls-tree', '-z', $commit, '--', $path]), "\0");
            self::assertMatchesRegularExpression('/\A(?:100644|100755) blob [a-f0-9]{40}\t/sD', $entry);
            $sources[$path] = $this->git(['--no-replace-objects', 'show', $commit.':'.$path], false);
        }
        ksort($sources, SORT_STRING);

        return self::$sourceCache[$commit] = $sources;
    }

    private function committedPaths(string $commit, string $prefix): array
    {
        $bytes = $this->git(
            ['--no-replace-objects', 'ls-tree', '-r', '--name-only', '-z', $commit, '--', $prefix],
            false,
        );
        $paths = array_values(array_filter(explode("\0", $bytes)));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function sourceContains(array $sources, string $needle): bool
    {
        foreach ($sources as $source) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasPathPrefix(array $sources, string $prefix): bool
    {
        foreach (array_keys($sources) as $path) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

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

    private function git(array $arguments, bool $trim = true): string
    {
        $process = new Process(['git', ...$arguments], dirname(__DIR__, 3));
        $process->setTimeout(30);
        $process->mustRun();

        return $trim ? trim($process->getOutput()) : $process->getOutput();
    }
}
