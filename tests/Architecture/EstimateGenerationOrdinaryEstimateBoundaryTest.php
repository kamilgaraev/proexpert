<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationOrdinaryEstimateBoundaryTest extends TestCase
{
    private const WRITER = 'Application/Apply/LaravelGeneratedEstimateWriter.php';

    /** @var array<string, array<int, string>> */
    private const WRITE_DEPENDENCIES = [
        self::WRITER => ['Estimate', 'EstimateItem', 'EstimateItemResource', 'EstimateSection'],
    ];

    /** @var array<string, array<int, string>> */
    private const READ_DEPENDENCIES = [
        'Models/EstimateGenerationLearningExample.php' => ['Estimate', 'EstimateItem'],
        'Models/EstimateGenerationSession.php' => ['Estimate'],
        'Services/EstimateGenerationExcelExportService.php' => ['Estimate'],
        'Services/Learning/EstimateGenerationLearningBootstrapService.php' => ['Estimate'],
        'Services/Learning/EstimateGenerationLearningRecorder.php' => ['Estimate'],
        'Services/Learning/EstimateLearningExampleExtractor.php' => ['Estimate', 'EstimateItem'],
    ];

    /** @var array<string, array<int, string>> */
    private const LOOKUP_DEPENDENCIES = [
        self::WRITER => ['MeasurementUnit'],
    ];

    private const READ_CONSTRUCTION_ALLOWLIST = [
        'Services/EstimateGenerationExcelExportService.php' => ['new'],
    ];

    #[Test]
    public function ordinary_estimate_dependencies_match_the_exact_read_write_boundary(): void
    {
        [$ordinary, $lookups] = $this->projectDependencies();

        self::assertSame(self::WRITE_DEPENDENCIES + self::READ_DEPENDENCIES, $ordinary);
        self::assertSame(self::LOOKUP_DEPENDENCIES, $lookups);
    }

    #[Test]
    public function writer_is_the_only_mutation_boundary_and_remains_create_only(): void
    {
        foreach ($this->projectSources() as $path => $source) {
            $mutations = $this->mutationTokens($source);

            if ($path === self::WRITER) {
                self::assertNotEmpty($mutations);
                self::assertSame([], array_values(array_diff($mutations, ['create'])));

                continue;
            }

            if (isset(self::READ_CONSTRUCTION_ALLOWLIST[$path])) {
                self::assertSame(self::READ_CONSTRUCTION_ALLOWLIST[$path], $mutations);

                continue;
            }

            self::assertSame([], $mutations, sprintf('%s mutates ordinary estimate models.', $path));
        }
    }

    #[Test]
    public function mutation_scanner_rejects_alias_fqcn_query_instance_relation_and_new_variants(): void
    {
        $fixtures = [
            <<<'PHP'
<?php
use App\Models\Estimate as Budget;
Budget::query()->whereKey(1)->update(['name' => 'x']);
PHP,
            <<<'PHP'
<?php
\App\Models\EstimateSection::destroy(1);
PHP,
            <<<'PHP'
<?php
use App\Models\EstimateItem;
$item = EstimateItem::query()->first();
$item->delete();
PHP,
            <<<'PHP'
<?php
use App\Models\Estimate;
$estimate = Estimate::find(1);
$estimate->items()->create(['name' => 'x']);
PHP,
            <<<'PHP'
<?php
use App\Models\EstimateItemResource as ResourceRow;
$row = new ResourceRow();
$row->save();
PHP,
        ];

        foreach ($fixtures as $fixture) {
            self::assertNotSame([], $this->mutationTokens($fixture));
        }
    }

    /**
     * @return array{array<string, array<int, string>>, array<string, array<int, string>>}
     */
    private function projectDependencies(): array
    {
        $ordinary = [];
        $lookups = [];

        foreach ($this->projectSources() as $path => $source) {
            $dependencies = array_keys($this->modelAliases($source));
            $ordinaryModels = array_values(array_intersect(
                ['Estimate', 'EstimateItem', 'EstimateItemResource', 'EstimateSection'],
                $dependencies,
            ));
            $lookupModels = array_values(array_intersect(['MeasurementUnit'], $dependencies));

            if ($ordinaryModels !== []) {
                $ordinary[$path] = $ordinaryModels;
            }

            if ($lookupModels !== []) {
                $lookups[$path] = $lookupModels;
            }
        }

        ksort($ordinary);
        ksort($lookups);

        return [$ordinary, $lookups];
    }

    /** @return array<string, string> */
    private function projectSources(): array
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        $sources = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }

    /** @return array<string, string> model => local alias */
    private function modelAliases(string $source): array
    {
        $models = ['Estimate', 'EstimateItem', 'EstimateItemResource', 'EstimateSection', 'MeasurementUnit'];
        $modelPattern = implode('|', $models);
        $aliases = [];
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_USE) {
                $statement = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                    $part = $tokens[$cursor];
                    $text = is_array($part) ? $part[1] : $part;

                    if ($text === ';' || $text === '{') {
                        break;
                    }

                    $statement .= $text;
                }

                if (preg_match(
                    '/^\s*App\\\\Models\\\\('.$modelPattern.')(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i',
                    $statement,
                    $import,
                ) === 1) {
                    $aliases[$import[1]] = ($import[2] ?? '') !== '' ? $import[2] : $import[1];
                }
            }

            if (! is_array($token) || ! in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            if (preg_match('/^\\\\?App\\\\Models\\\\('.$modelPattern.')$/', $token[1], $fqcn) === 1) {
                $aliases[$fqcn[1]] ??= str_starts_with($token[1], '\\') ? $token[1] : $fqcn[1];
            }
        }

        ksort($aliases);

        return $aliases;
    }

    /** @return array<int, string> */
    private function mutationTokens(string $source): array
    {
        $aliases = $this->modelAliases($source);
        $mutations = [];
        $mutationPattern = 'create|forceCreate|update|updateOrCreate|delete|forceDelete|destroy|insert|upsert|save';

        foreach ($aliases as $alias) {
            $class = preg_quote($alias, '/');

            if (preg_match_all('/'.$class.'::('.$mutationPattern.')\s*\(/s', $source, $matches)) {
                array_push($mutations, ...$matches[1]);
            }

            if (preg_match_all('/'.$class.'::query\s*\(\s*\)[^;]*?->('.$mutationPattern.')\s*\(/s', $source, $matches)) {
                array_push($mutations, ...$matches[1]);
            }

            if (preg_match('/new\s+'.$class.'\s*\(/', $source)) {
                $mutations[] = 'new';
            }

            preg_match_all('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*'.$class.'::/s', $source, $variables);
            foreach ($variables[1] ?? [] as $variable) {
                $variablePattern = preg_quote($variable, '/');
                if (preg_match_all(
                    '/'.$variablePattern.'->(?:[A-Za-z_][A-Za-z0-9_]*\([^;]*?\)->)*('.$mutationPattern.')\s*\(/s',
                    $source,
                    $matches,
                )) {
                    array_push($mutations, ...$matches[1]);
                }
            }
        }

        $mutations = array_values(array_unique($mutations));
        sort($mutations);

        return $mutations;
    }
}
