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
    private const READ_DEPENDENCIES = [];

    /** @var array<string, array<int, string>> */
    private const LOOKUP_DEPENDENCIES = [
        self::WRITER => ['MeasurementUnit'],
    ];

    /** @var array<string, array<int, string>> */
    private const RAW_READ_DEPENDENCIES = [];

    /** Virtual unsaved model required by the existing Excel builder contract. */
    private const READ_ONLY_CONSTRUCTION = [];

    #[Test]
    public function dependencies_match_the_exact_write_read_lookup_and_raw_read_boundaries(): void
    {
        $sources = $this->projectSources();
        $analyzer = $this->analyzer($sources);
        $ordinary = [];
        $lookups = [];
        $rawTables = [];

        foreach ($sources as $path => $source) {
            $dependencies = array_keys($analyzer->modelAliases($source));
            $models = array_values(array_intersect(
                ['Estimate', 'EstimateItem', 'EstimateItemResource', 'EstimateSection'],
                $dependencies,
            ));
            $measurementUnits = array_values(array_intersect(['MeasurementUnit'], $dependencies));
            $tables = $analyzer->rawTables($source);

            if ($models !== []) {
                $ordinary[$path] = $models;
            }
            if ($measurementUnits !== []) {
                $lookups[$path] = $measurementUnits;
            }
            if ($tables !== []) {
                $rawTables[$path] = $tables;
            }
        }

        ksort($ordinary);
        ksort($lookups);
        ksort($rawTables);

        self::assertSame(self::WRITE_DEPENDENCIES + self::READ_DEPENDENCIES, $ordinary);
        self::assertSame(self::LOOKUP_DEPENDENCIES, $lookups);
        self::assertSame(self::RAW_READ_DEPENDENCIES, $rawTables);
    }

    #[Test]
    public function writer_is_the_only_create_graph_boundary_and_every_other_dependency_is_read_only(): void
    {
        $sources = $this->projectSources();
        $analyzer = $this->analyzer($sources);

        foreach ($sources as $path => $source) {
            $mutations = $analyzer->mutations($source);

            if ($path === self::WRITER) {
                self::assertNotEmpty($mutations);
                self::assertSame([], array_values(array_diff($mutations, ['create'])));

                continue;
            }

            if (isset(self::READ_ONLY_CONSTRUCTION[$path])) {
                self::assertSame(self::READ_ONLY_CONSTRUCTION[$path], $mutations);

                continue;
            }

            self::assertSame([], $mutations, sprintf('%s mutates an ordinary estimate boundary.', $path));
        }
    }

    #[Test]
    public function analyzer_rejects_all_known_model_relation_group_use_and_raw_query_bypasses(): void
    {
        $analyzer = new OrdinaryEstimateBoundaryAnalyzer(['appliedEstimate']);
        $fixtures = [
            '<?php use App\\Models\\Estimate; Estimate::whereKey(1)->update([]);',
            '<?php use App\\Models\\Estimate; Estimate::find(1)->delete();',
            '<?php $session->appliedEstimate()->update([]);',
            '<?php use App\\Models\\EstimateItem; $row = new EstimateItem; $row->save();',
            '<?php use App\\Models\\{Estimate, EstimateItem}; EstimateItem::query()->upsert([], []);',
            '<?php use App\\Models\\Estimate as Budget; Budget::query()->whereKey(1)->update([]);',
            '<?php \\App\\Models\\EstimateSection::destroy(1);',
            '<?php use App\\Models\\Estimate; $estimate = Estimate::find(1); $estimate->items()->create([]);',
            "<?php DB::table('estimates')->where('id', 1)->update([]);",
            "<?php DB::query()->from('estimate_sections')->delete();",
            "<?php DB::table('estimate_items')->upsert([], []);",
            "<?php DB::statement('delete from estimate_item_resources where id = 1');",
            "<?php \$query = DB::table('estimate_items'); \$query->delete();",
        ];

        foreach ($fixtures as $fixture) {
            self::assertNotSame([], $analyzer->mutations($fixture), $fixture);
        }
    }

    #[Test]
    public function analyzer_accepts_model_and_raw_table_read_lookups(): void
    {
        $source = <<<'PHP'
<?php
use App\Models\Estimate;
$estimate = Estimate::query()->whereKey(1)->first();
$exists = DB::table('estimates')->where('organization_id', 10)->exists();
PHP;

        self::assertSame([], $this->analyzer($this->projectSources())->mutations($source));
    }

    /** @param array<string, string> $sources */
    private function analyzer(array $sources): OrdinaryEstimateBoundaryAnalyzer
    {
        return new OrdinaryEstimateBoundaryAnalyzer($this->ordinaryRelationNames($sources));
    }

    /**
     * Discover model relations which return an ordinary estimate model, so relation writes cannot bypass imports.
     *
     * @param  array<string, string>  $sources
     * @return array<int, string>
     */
    private function ordinaryRelationNames(array $sources): array
    {
        $relations = [];
        $plainAnalyzer = new OrdinaryEstimateBoundaryAnalyzer([]);

        foreach ($sources as $source) {
            $aliases = $plainAnalyzer->modelAliases($source);
            foreach ($aliases as $model => $alias) {
                if ($model === 'MeasurementUnit') {
                    continue;
                }

                $class = preg_quote($alias, '/');
                if (preg_match_all(
                    '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)[^{]*\{[^{}]*belongsTo\s*\(\s*'.$class.'::class/s',
                    $source,
                    $matches,
                )) {
                    array_push($relations, ...$matches[1]);
                }
            }
        }

        $relations = array_values(array_unique($relations));
        sort($relations);

        return $relations;
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
}

final class OrdinaryEstimateBoundaryAnalyzer
{
    private const MODELS = ['Estimate', 'EstimateItem', 'EstimateItemResource', 'EstimateSection', 'MeasurementUnit'];

    private const TABLES = ['estimates', 'estimate_sections', 'estimate_items', 'estimate_item_resources'];

    /** @param array<int, string> $ordinaryRelations */
    public function __construct(private array $ordinaryRelations) {}

    /** @return array<string, string> model => local alias */
    public function modelAliases(string $source): array
    {
        $modelPattern = implode('|', self::MODELS);
        $aliases = [];
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_USE) {
                $statement = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                    $part = $tokens[$cursor];
                    $text = is_array($part) ? $part[1] : $part;
                    if ($text === ';') {
                        break;
                    }
                    $statement .= $text;
                }

                $this->addUseStatementAliases(trim($statement), $modelPattern, $aliases);
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
    public function rawTables(string $source): array
    {
        $tables = [];
        $tablePattern = implode('|', self::TABLES);
        preg_match_all(
            '/(?:table|from)\s*\(\s*[\'\"]('.$tablePattern.')[\'\"]\s*\)/i',
            $source,
            $matches,
        );
        array_push($tables, ...($matches[1] ?? []));

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /** @return array<int, string> */
    public function mutations(string $source): array
    {
        $mutations = [];
        $mutationPattern = 'create|forceCreate|update|updateOrCreate|delete|forceDelete|destroy|insert|upsert|save';

        foreach ($this->modelAliases($source) as $alias) {
            $class = preg_quote($alias, '/').'(?![A-Za-z0-9_\\\\])';
            if (preg_match_all(
                '/'.$class.'::(?:[A-Za-z_][A-Za-z0-9_]*\([^;]*?\)->)*('.$mutationPattern.')\s*\(/s',
                $source,
                $matches,
            )) {
                array_push($mutations, ...$matches[1]);
            }

            if (preg_match('/new\s+'.$class.'(?:\s*\(|\s*;)/', $source)) {
                $mutations[] = 'new';
            }

            preg_match_all(
                '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:new\s+)?'.$class.'(?:::)?[^;]*;/s',
                $source,
                $variables,
            );
            $this->collectVariableMutations($source, $variables[1] ?? [], $mutationPattern, $mutations);
        }

        foreach ($this->ordinaryRelations as $relation) {
            if (preg_match_all(
                '/\$[A-Za-z_][A-Za-z0-9_]*->'.preg_quote($relation, '/').'\s*\(\s*\)[^;]*?->?('.$mutationPattern.')\s*\(/s',
                $source,
                $matches,
            )) {
                array_push($mutations, ...$matches[1]);
            }
        }

        $this->collectRawMutations($source, $mutationPattern, $mutations);

        $mutations = array_values(array_unique($mutations));
        sort($mutations);

        return $mutations;
    }

    /** @param array<string, string> $aliases */
    private function addUseStatementAliases(string $statement, string $modelPattern, array &$aliases): void
    {
        if (preg_match(
            '/^App\\\\Models\\\\('.$modelPattern.')(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/i',
            $statement,
            $import,
        ) === 1) {
            $aliases[$import[1]] = ($import[2] ?? '') !== '' ? $import[2] : $import[1];

            return;
        }

        if (preg_match('/^App\\\\Models\\\\\{(.+)\}$/s', $statement, $group) !== 1) {
            return;
        }

        foreach (explode(',', $group[1]) as $member) {
            if (preg_match(
                '/^\s*('.$modelPattern.')(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i',
                $member,
                $import,
            ) === 1) {
                $aliases[$import[1]] = ($import[2] ?? '') !== '' ? $import[2] : $import[1];
            }
        }
    }

    /**
     * @param  array<int, string>  $variables
     * @param  array<int, string>  $mutations
     */
    private function collectVariableMutations(
        string $source,
        array $variables,
        string $mutationPattern,
        array &$mutations,
    ): void {
        foreach ($variables as $variable) {
            if (preg_match_all(
                '/'.preg_quote($variable, '/').'->(?:[A-Za-z_][A-Za-z0-9_]*\([^;]*?\)->)*('.$mutationPattern.')\s*\(/s',
                $source,
                $matches,
            )) {
                array_push($mutations, ...$matches[1]);
            }
        }
    }

    /** @param array<int, string> $mutations */
    private function collectRawMutations(string $source, string $mutationPattern, array &$mutations): void
    {
        $tablePattern = implode('|', self::TABLES);
        if (preg_match_all(
            '/(?:table|from)\s*\(\s*[\'\"]('.$tablePattern.')[\'\"]\s*\)[^;]*?->('.$mutationPattern.')\s*\(/is',
            $source,
            $matches,
        )) {
            array_push($mutations, ...$matches[2]);
        }

        if (preg_match_all(
            '/(?:statement|unprepared|affectingStatement)\s*\(\s*[\'\"]\s*(insert\s+into|update|delete\s+from|upsert)\s+('.$tablePattern.')\b/is',
            $source,
            $matches,
        )) {
            array_push($mutations, ...$matches[1]);
        }

        if (preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:DB::)?(?:table|from)\s*\(\s*[\'\"]('.$tablePattern.')[\'\"]\s*\)[^;]*;/is',
            $source,
            $variables,
        )) {
            $this->collectVariableMutations($source, $variables[1], $mutationPattern, $mutations);
        }
    }
}
