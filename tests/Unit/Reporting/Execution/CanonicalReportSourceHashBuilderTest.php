<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class CanonicalReportSourceHashBuilderTest extends TestCase
{
    private const BASELINE_HASH = 'ca9dc342d70837357f04736b64b7e5ab4c5b3c0afdd93c6fe5fb07e8d8fc158a';

    public function test_closed_projection_has_the_exact_known_digest(): void
    {
        [$query, $snapshot, $result] = $this->fixture($this->baselineSources());

        self::assertSame(
            self::BASELINE_HASH,
            (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result)->value,
        );
    }

    #[DataProvider('closedProjectionMutationProvider')]
    public function test_every_closed_projection_leaf_changes_the_digest(string $mutation, mixed $value): void
    {
        [$baselineQuery, $baselineSnapshot, $baselineResult] = $this->fixture($this->baselineSources());
        [$mutatedQuery, $mutatedSnapshot, $mutatedResult] = $this->fixture(
            $this->baselineSources(),
            [],
            [$mutation => $value],
        );
        $builder = new CanonicalReportSourceHashBuilder;

        self::assertNotSame(
            $builder->build($baselineQuery, $baselineSnapshot, $baselineResult)->value,
            $builder->build($mutatedQuery, $mutatedSnapshot, $mutatedResult)->value,
            $mutation,
        );
    }

    public static function closedProjectionMutationProvider(): array
    {
        return [
            'query definition hash' => ['definition_hash', str_repeat('b', 64)],
            'query contract version' => ['contract_version', 'contract-v2'],
            'query formula version' => ['query_formula_version', 'formula-v2'],
            'query source schema version' => ['source_schema_version', 'schema-v2'],
            'query renderer version' => ['renderer_version', 'renderer-v2'],
            'canonical query filters and query hash' => ['filters', ['period' => 'previous']],
            'canonical query comparison and query hash' => ['comparison', ['period' => 'year']],
            'canonical query as-of and query hash' => ['as_of', '2026-07-29T10:15:31.123456+03:00'],
            'canonical query locale and query hash' => ['locale', 'en-US'],
            'snapshot kind' => ['snapshot_kind', 'replica'],
            'snapshot id' => ['snapshot_id', 'snapshot-2'],
            'scope organization' => ['organization_id', 2],
            'scope holding organizations' => ['holding_organization_ids', [1, 2]],
            'scope projects' => ['project_ids', [9, 10]],
            'scope resources' => ['resources', [
                new ReportScopedResource('task', 7, 9),
                new ReportScopedResource('task', 8, 9),
            ]],
            'scope timezone' => ['timezone', 'UTC'],
            'snapshot definition hash' => ['snapshot_definition_hash', str_repeat('b', 64)],
            'snapshot formula version' => ['snapshot_formula_version', 'formula-snapshot-v2'],
            'snapshot generated instant' => ['generated_at', '2026-07-29T07:15:31.123456Z'],
            'snapshot stale instant' => ['stale_at', '2026-07-29T08:15:31.123456Z'],
            'snapshot watermarks' => ['watermarks', ['ledger' => 'wm_2', 'projects' => 'wm_1']],
            'result row count' => ['row_count', 13],
            'provenance source of truth' => ['source_of_truth', 'replica'],
            'provenance external confirmation role' => ['external_confirmation_role', 'auditor'],
            'source ref source' => ['source_ref_source', 'gamma'],
            'source ref snapshot kind' => ['source_ref_snapshot_kind', 'view'],
            'source ref snapshot id' => ['source_ref_snapshot_id', 'snapshot_three'],
            'source ref schema version' => ['source_ref_schema_version', 'schema_three'],
            'source ref watermark' => ['source_ref_watermark', 'wm_three'],
            'source ref row count' => ['source_ref_row_count', 31],
            'source ref hash' => ['source_ref_hash', str_repeat('f', 64)],
        ];
    }

    public function test_hash_is_stable_for_source_ref_order_and_does_not_mutate_input(): void
    {
        [$query, $snapshot, $result] = $this->fixture([
            $this->source('zeta', 'v2', 4, 'b'),
            $this->source('alpha', 'v1', 3, 'a'),
        ]);
        $before = $result->provenance->sourceRefs;

        $hash = (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result);
        [, , $reordered] = $this->fixture(array_reverse($before));

        self::assertSame($hash->value, (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $reordered)->value);
        self::assertSame($before, $result->provenance->sourceRefs);
    }

    #[DataProvider('sourceRefSortFieldProvider')]
    public function test_source_ref_sorting_is_stable_for_each_reachable_identity_sort_field(string $field): void
    {
        $first = [
            'source' => 'alpha',
            'snapshot_kind' => 'table',
            'snapshot_id' => 'snapshot_a',
            'schema_version' => 'schema_a',
            'watermark' => 'wm_a',
            'row_count' => 3,
            'materialized_source_hash' => str_repeat('a', 64),
        ];
        $second = $first;
        $second[$field] = match ($field) {
            'source' => 'beta',
            'snapshot_kind' => 'view',
            'snapshot_id' => 'snapshot_b',
            'schema_version' => 'schema_b',
            'watermark' => 'wm_b',
        };
        $second['row_count'] = 4;
        $second['materialized_source_hash'] = str_repeat('b', 64);
        $sources = [$this->sourceFromProjection($first), $this->sourceFromProjection($second)];
        [$query, $snapshot, $result] = $this->fixture($sources);
        [, , $reversed] = $this->fixture(array_reverse($sources));
        $builder = new CanonicalReportSourceHashBuilder;

        self::assertSame(
            $builder->build($query, $snapshot, $result)->value,
            $builder->build($query, $snapshot, $reversed)->value,
            $field,
        );
    }

    public static function sourceRefSortFieldProvider(): array
    {
        return array_map(
            static fn (string $field): array => [$field],
            ['source', 'snapshot_kind', 'snapshot_id', 'schema_version', 'watermark'],
        );
    }

    public function test_source_ref_comparator_ast_binds_the_exact_constant_to_the_usort_data_flow(): void
    {
        self::assertSame([], $this->sourceRefComparatorContractViolations($this->builderSource()));
    }

    #[DataProvider('sourceRefComparatorSourceMutantProvider')]
    public function test_source_ref_comparator_ast_rejects_every_controlled_source_mutant(
        string $search,
        string $replacement,
    ): void {
        $source = $this->builderSource();
        $mutant = str_replace($search, $replacement, $source, $replacementCount);

        self::assertGreaterThan(0, $replacementCount, 'Controlled mutant did not alter production source.');
        self::assertNotSame([], $this->sourceRefComparatorContractViolations($mutant));
    }

    public static function sourceRefComparatorSourceMutantProvider(): array
    {
        return [
            'unused exact constant with local first five fields' => [
                'foreach (self::SOURCE_REF_SORT_FIELDS as $field)',
                "foreach (['source', 'snapshot_kind', 'snapshot_id', 'schema_version', 'watermark'] as \$field)",
            ],
            'row count removed from constant' => [
                "        'row_count',\n",
                '',
            ],
            'materialized source hash removed from constant' => [
                "        'materialized_source_hash',\n",
                '',
            ],
            'constant precedence reordered' => [
                "        'source',\n        'snapshot_kind',",
                "        'snapshot_kind',\n        'source',",
            ],
            'foreach bypassed' => [
                'foreach (self::SOURCE_REF_SORT_FIELDS as $field) {',
                'if (false) {',
            ],
            'spaceship comparison bypassed' => [
                '$comparison = $left[$field] <=> $right[$field];',
                '$comparison = 0;',
            ],
            'first nonzero return bypassed' => [
                'return $comparison;',
                'return 0;',
            ],
            'terminal equality return bypassed' => [
                "\n            return 0;\n        });",
                "\n            return 1;\n        });",
            ],
        ];
    }

    public function test_canonical_hash_does_not_depend_on_its_own_snapshot_or_provenance_hash(): void
    {
        [$query, $snapshot, $result] = $this->fixture($this->baselineSources());
        [, $changedSnapshot, $changedResult] = $this->fixture(
            $this->baselineSources(),
            [],
            ['source_hash' => str_repeat('e', 64)],
        );
        $builder = new CanonicalReportSourceHashBuilder;

        self::assertSame(
            $builder->build($query, $snapshot, $result)->value,
            $builder->build($query, $changedSnapshot, $changedResult)->value,
        );
    }

    public function test_seal_payload_hash_is_excluded_from_projection_to_prevent_circular_hashing(): void
    {
        [$query, $firstSnapshot, $firstResult] = $this->fixture(
            $this->baselineSources(),
            [],
            ['official_seal_payload_hash' => str_repeat('d', 64)],
        );
        [, $secondSnapshot, $secondResult] = $this->fixture(
            $this->baselineSources(),
            [],
            ['official_seal_payload_hash' => str_repeat('e', 64)],
        );
        $builder = new CanonicalReportSourceHashBuilder;

        self::assertSame(
            $builder->build($query, $firstSnapshot, $firstResult)->value,
            $builder->build($query, $secondSnapshot, $secondResult)->value,
        );
    }

    public function test_duplicate_five_field_identity_is_rejected_even_when_characteristics_differ(): void
    {
        [$query, $snapshot, $result] = $this->fixture([
            $this->source('alpha', 'v1', 3, 'a'),
            $this->source('alpha', 'v1', 9, 'b'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_source_hash_invalid');
        (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result);
    }

    #[DataProvider('invalidDecimalProvider')]
    public function test_invalid_decimal_or_float_is_rejected(mixed $value): void
    {
        [$query, $snapshot, $result] = $this->fixture([$this->source('alpha', 'v1', 3, 'a')], ['metric' => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_source_hash_invalid');
        (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result);
    }

    public static function invalidDecimalProvider(): array
    {
        return [
            'float' => [1.25],
            'plus' => ['+1'],
            'exponent' => ['1e3'],
            'leading-zero' => ['01'],
            'trailing-zero' => ['1.20'],
            'zero-fraction' => ['0.0'],
            'negative-zero' => ['-0'],
            'negative-zero-fraction' => ['-0.00'],
        ];
    }

    #[DataProvider('validDecimalProvider')]
    public function test_canonical_decimal_strings_are_accepted(string $value): void
    {
        [$query, $snapshot, $result] = $this->fixture([$this->source('alpha', 'v1', 3, 'a')], ['metric' => $value]);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result)->value);
    }

    public static function validDecimalProvider(): array
    {
        return [['0'], ['-1'], ['1.25'], ['-0.5']];
    }

    private function fixture(array $sources, array $comparison = [], array $overrides = []): array
    {
        $organizationId = $overrides['organization_id'] ?? 1;
        $scope = new ReportScope(
            $organizationId,
            $overrides['holding_organization_ids'] ?? [$organizationId],
            $overrides['project_ids'] ?? [9],
            $overrides['resources'] ?? [new ReportScopedResource('task', 7, 9)],
            new DateTimeZone($overrides['timezone'] ?? 'Europe/Moscow'),
        );
        $definition = (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash($overrides['definition_hash'] ?? str_repeat('a', 64)))
            ->contractVersion($overrides['contract_version'] ?? '1')
            ->formulaVersion($overrides['query_formula_version'] ?? '1')
            ->sourceSchemaVersion($overrides['source_schema_version'] ?? '1')
            ->rendererVersion($overrides['renderer_version'] ?? '1')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet($overrides['filters'] ?? []),
            $overrides['comparison'] ?? $comparison,
            new DateTimeImmutable($overrides['as_of'] ?? '2026-07-29T10:15:30.123456+03:00'),
            $overrides['locale'] ?? 'ru-RU',
        );
        $hash = new Sha256Hash($overrides['source_hash'] ?? str_repeat('c', 64));
        if (isset($overrides['source_ref_source'])) {
            $sources[0] = new ReportSourceRef(
                $overrides['source_ref_source'],
                $overrides['source_ref_snapshot_kind'] ?? $sources[0]->snapshotKind,
                $overrides['source_ref_snapshot_id'] ?? $sources[0]->snapshotId,
                $overrides['source_ref_schema_version'] ?? $sources[0]->schemaVersion,
                $overrides['source_ref_watermark'] ?? $sources[0]->watermark,
                $overrides['source_ref_row_count'] ?? $sources[0]->rowCount,
                new Sha256Hash($overrides['source_ref_hash'] ?? $sources[0]->hash->value),
            );
        } elseif (array_key_exists('source_ref_snapshot_kind', $overrides)
            || array_key_exists('source_ref_snapshot_id', $overrides)
            || array_key_exists('source_ref_schema_version', $overrides)
            || array_key_exists('source_ref_watermark', $overrides)
            || array_key_exists('source_ref_row_count', $overrides)
            || array_key_exists('source_ref_hash', $overrides)) {
            $sources[0] = new ReportSourceRef(
                $sources[0]->source,
                $overrides['source_ref_snapshot_kind'] ?? $sources[0]->snapshotKind,
                $overrides['source_ref_snapshot_id'] ?? $sources[0]->snapshotId,
                $overrides['source_ref_schema_version'] ?? $sources[0]->schemaVersion,
                $overrides['source_ref_watermark'] ?? $sources[0]->watermark,
                $overrides['source_ref_row_count'] ?? $sources[0]->rowCount,
                new Sha256Hash($overrides['source_ref_hash'] ?? $sources[0]->hash->value),
            );
        }
        $generatedAt = new DateTimeImmutable($overrides['generated_at'] ?? '2026-07-29T07:15:30.123456Z');
        $seal = array_key_exists('official_seal_payload_hash', $overrides)
            ? new ReportSnapshotSeal(
                'seal-key-1',
                'ed25519-sha256',
                new Sha256Hash($overrides['official_seal_payload_hash']),
                rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='),
                new DateTimeImmutable('2026-07-29T08:15:30.123456Z'),
            )
            : null;
        $snapshot = new ReportSnapshotRef(
            $overrides['snapshot_kind'] ?? 'materialized',
            $overrides['snapshot_id'] ?? 'snapshot-1',
            $scope,
            new Sha256Hash($overrides['snapshot_definition_hash'] ?? $definition->definitionHash->value),
            $overrides['snapshot_formula_version'] ?? $definition->formulaVersion,
            $hash,
            $generatedAt,
            new DateTimeImmutable($overrides['stale_at'] ?? '2026-07-29T08:15:30.123456Z'),
            $overrides['watermarks'] ?? ['ledger' => 'wm_1', 'projects' => 'wm_1'],
            $seal === null ? ReportSnapshotClassification::OPERATIONAL : ReportSnapshotClassification::OFFICIAL,
            $seal,
        );
        $result = new ReportResult(
            new ReportResultMetadata($snapshot, $overrides['row_count'] ?? 12, $snapshot->generatedAt, $snapshot->staleAt),
            [],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []),
            new ReportProvenance(
                $overrides['source_of_truth'] ?? 'primary',
                $sources,
                $hash,
                $overrides['external_confirmation_role'] ?? 'controller',
            ),
            [['id' => 'name']],
            [],
        );

        return [$query, $snapshot, $result];
    }

    private function baselineSources(): array
    {
        return [
            new ReportSourceRef('alpha', 'table', 'snapshot_a', 'schema_a', 'wm_a', 3, new Sha256Hash(str_repeat('a', 64))),
            new ReportSourceRef('beta', 'view', 'snapshot_b', 'schema_b', 'wm_b', 4, new Sha256Hash(str_repeat('b', 64))),
        ];
    }

    private function sourceFromProjection(array $projection): ReportSourceRef
    {
        return new ReportSourceRef(
            $projection['source'],
            $projection['snapshot_kind'],
            $projection['snapshot_id'],
            $projection['schema_version'],
            $projection['watermark'],
            $projection['row_count'],
            new Sha256Hash($projection['materialized_source_hash']),
        );
    }

    private function builderSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Application/Execution/CanonicalReportSourceHashBuilder.php',
        );
        self::assertIsString($source);

        return $source;
    }

    private function sourceRefComparatorContractViolations(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        if ($nodes === null) {
            return ['source_did_not_parse'];
        }

        $finder = new NodeFinder;
        $constants = $finder->findInstanceOf($nodes, ClassConst::class);
        $sortConstant = null;
        foreach ($constants as $constantStatement) {
            foreach ($constantStatement->consts as $constant) {
                if ($constant->name->toString() === 'SOURCE_REF_SORT_FIELDS') {
                    $sortConstant = $constant->value;
                }
            }
        }

        $violations = [];
        $expectedFields = [
            'source',
            'snapshot_kind',
            'snapshot_id',
            'schema_version',
            'watermark',
            'row_count',
            'materialized_source_hash',
        ];
        if (! $sortConstant instanceof Array_) {
            $violations[] = 'sort_constant_missing';
        } else {
            $actualFields = [];
            foreach ($sortConstant->items as $item) {
                $actualFields[] = $item?->value instanceof String_ ? $item->value->value : null;
            }
            if ($actualFields !== $expectedFields) {
                $violations[] = 'sort_constant_not_exact';
            }
        }

        $usortClosure = null;
        foreach ($finder->findInstanceOf($nodes, FuncCall::class) as $call) {
            if ($call->name instanceof Name
                && strtolower($call->name->toString()) === 'usort'
                && isset($call->args[0], $call->args[1])
                && $this->isVariable($call->args[0]->value, 'sourceRefs')
                && $call->args[1]->value instanceof Closure) {
                $usortClosure = $call->args[1]->value;
                break;
            }
        }
        if (! $usortClosure instanceof Closure) {
            return [...$violations, 'source_refs_usort_closure_missing'];
        }
        if (count($usortClosure->params) !== 2
            || ! $this->isVariable($usortClosure->params[0]->var, 'left')
            || ! $this->isVariable($usortClosure->params[1]->var, 'right')) {
            $violations[] = 'comparator_parameters_not_exact';
        }

        $statements = $usortClosure->stmts ?? [];
        $loop = $statements[0] ?? null;
        if (! $loop instanceof Foreach_
            || ! $loop->expr instanceof ClassConstFetch
            || ! $loop->expr->class instanceof Name
            || strtolower($loop->expr->class->toString()) !== 'self'
            || $loop->expr->name->toString() !== 'SOURCE_REF_SORT_FIELDS'
            || ! $this->isVariable($loop->valueVar, 'field')) {
            $violations[] = 'foreach_not_bound_to_sort_constant';
        } else {
            $assignmentStatement = $loop->stmts[0] ?? null;
            $assignment = $assignmentStatement instanceof Expression ? $assignmentStatement->expr : null;
            if (! $assignment instanceof Assign
                || ! $this->isVariable($assignment->var, 'comparison')
                || ! $assignment->expr instanceof Spaceship
                || ! $this->isIndexedVariable($assignment->expr->left, 'left', 'field')
                || ! $this->isIndexedVariable($assignment->expr->right, 'right', 'field')) {
                $violations[] = 'spaceship_data_flow_not_exact';
            }

            $nonzeroBranch = $loop->stmts[1] ?? null;
            $nonzeroReturn = $nonzeroBranch instanceof If_ ? ($nonzeroBranch->stmts[0] ?? null) : null;
            if (! $nonzeroBranch instanceof If_
                || ! $nonzeroBranch->cond instanceof NotIdentical
                || ! $this->isVariable($nonzeroBranch->cond->left, 'comparison')
                || ! $nonzeroBranch->cond->right instanceof Int_
                || $nonzeroBranch->cond->right->value !== 0
                || ! $nonzeroReturn instanceof Return_
                || ! $this->isVariable($nonzeroReturn->expr, 'comparison')
                || count($loop->stmts) !== 2) {
                $violations[] = 'first_nonzero_return_not_exact';
            }
        }

        $terminalReturn = $statements[1] ?? null;
        if (! $terminalReturn instanceof Return_
            || ! $terminalReturn->expr instanceof Int_
            || $terminalReturn->expr->value !== 0
            || count($statements) !== 2) {
            $violations[] = 'terminal_zero_return_not_exact';
        }

        return $violations;
    }

    private function isVariable(Node\Expr $expression, string $name): bool
    {
        return $expression instanceof Variable && $expression->name === $name;
    }

    private function isIndexedVariable(Node\Expr $expression, string $variable, string $index): bool
    {
        return $expression instanceof ArrayDimFetch
            && $expression->var instanceof Variable
            && $expression->var->name === $variable
            && $expression->dim instanceof Variable
            && $expression->dim->name === $index;
    }

    private function source(string $source, string $watermark, int $rowCount, string $hashSeed): ReportSourceRef
    {
        return new ReportSourceRef(
            $source,
            'table',
            'snapshot',
            'v1',
            $watermark,
            $rowCount,
            new Sha256Hash(str_repeat($hashSeed, 64)),
        );
    }
}
