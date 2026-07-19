<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class ContractMutationAstScanner
{
    /** @var array<string,list<Node>> */
    private array $parseCache = [];

    /** @var array<string,array{bodies:array<string,string>,calls:array<string,list<string>>}> */
    private array $structureCache = [];

    /** @var array<string,list<array{file:string,line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string,evidence:string}>> */
    private array $projectFindingsCache = [];

    private int $projectCacheHits = 0;

    private int $projectCacheMisses = 0;

    private const MUTATIONS = ['save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'forceDelete', 'insert', 'upsert', 'increment', 'decrement', 'touch', 'restore', 'create'];

    private const RAW_SQL_ENTRY_POINTS = [
        'statement',
        'unprepared',
        'insert',
        'update',
        'delete',
        'affectingStatement',
        'raw',
        'select',
        'selectOne',
        'selectResultSets',
        'selectFromWriteConnection',
        'scalar',
        'cursor',
    ];

    private const PDO_ENTRY_POINTS = ['exec', 'query', 'prepare'];

    private const SAFE_SQL_FUNCTIONS = [
        'abs' => 'immutable numeric projection', 'array_agg' => 'immutable aggregate', 'avg' => 'immutable aggregate',
        'cast' => 'immutable type conversion', 'ceil' => 'immutable numeric projection', 'coalesce' => 'immutable null projection',
        'concat' => 'immutable text projection', 'concat_ws' => 'immutable text projection', 'count' => 'immutable aggregate',
        'current_setting' => 'read-only session setting', 'date_trunc' => 'immutable date projection',
        'dense_rank' => 'immutable window projection', 'encode' => 'immutable binary projection', 'extract' => 'immutable date projection',
        'floor' => 'immutable numeric projection', 'greatest' => 'immutable scalar projection', 'json_agg' => 'immutable aggregate',
        'json_build_array' => 'immutable json projection', 'json_build_object' => 'immutable json projection',
        'jsonb_agg' => 'immutable aggregate', 'jsonb_build_array' => 'immutable json projection',
        'jsonb_build_object' => 'immutable json projection', 'lag' => 'immutable window projection', 'lead' => 'immutable window projection',
        'least' => 'immutable scalar projection', 'lower' => 'immutable text projection', 'max' => 'immutable aggregate',
        'min' => 'immutable aggregate', 'now' => 'read-only transaction timestamp', 'nullif' => 'immutable scalar projection',
        'percentile_cont' => 'immutable aggregate', 'rank' => 'immutable window projection', 'round' => 'immutable numeric projection',
        'row_number' => 'immutable window projection', 'sha256' => 'immutable digest', 'string_agg' => 'immutable aggregate',
        'sum' => 'immutable aggregate', 'to_char' => 'immutable formatting', 'upper' => 'immutable text projection',
        'convert_to' => 'immutable encoding projection', 'hashtext' => 'immutable digest', 'hashtextextended' => 'immutable digest',
        'radians' => 'immutable numeric projection', 'sin' => 'immutable numeric projection',
        'array' => 'SQL array constructor grammar', 'exists' => 'SQL existence predicate grammar',
        'in' => 'SQL membership predicate grammar', 'over' => 'SQL window grammar', 'values' => 'SQL values grammar',
        'regexp_replace' => 'immutable text projection', 'jsonb_array_elements_text' => 'immutable json projection',
        'to_tsvector' => 'immutable text-search projection', 'setweight' => 'immutable text-search projection',
        'array_to_string' => 'immutable text projection', 'jsonb_set' => 'immutable json projection',
        'jsonb_typeof' => 'immutable json inspection', 'jsonb_array_length' => 'immutable json inspection',
    ];

    /** @return list<int> */
    public function scan(string $source): array
    {
        return array_values(array_unique(array_column($this->findings($source), 'line')));
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string,evidence:string}> */
    public function findings(string $source): array
    {
        $nodes = $this->parse($source);

        return $this->findingsFromNodes($nodes, $this->methodStructuralHashes($nodes));
    }

    /**
     * @param  list<string>  $files
     * @return list<array{file:string,line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string,evidence:string}>
     */
    public function findingsInFiles(array $files): array
    {
        sort($files, SORT_STRING);
        $pendingFindings = [];
        $bodies = [];
        $calls = [];
        $sources = [];
        $declarations = [];
        foreach ($files as $file) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new \RuntimeException('contract_ast_source_unreadable:'.$file);
            }
            $source = file_get_contents($file);
            if (! is_string($source)) {
                throw new \RuntimeException('contract_ast_source_unreadable:'.$file);
            }
            $sources[$file] = $source;
            preg_match('/\bnamespace\s+([^;{]+)[;{]/', $source, $namespaceMatch);
            $namespace = trim($namespaceMatch[1] ?? '');
            preg_match_all('/\b(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $classMatches);
            foreach ($classMatches[1] as $class) {
                $declarations[$class] = $file;
                $declarations[ltrim($namespace.'\\'.$class, '\\')] = $file;
            }
            preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $functionMatches);
            foreach ($functionMatches[1] as $function) {
                $declarations[$function] ??= $file;
                $declarations[ltrim($namespace.'\\'.$function, '\\')] ??= $file;
            }
        }
        $snapshot = hash('sha256', json_encode(array_map(
            static fn (string $file, string $source): array => [$file, hash('sha256', $source)],
            array_keys($sources),
            array_values($sources),
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (isset($this->projectFindingsCache[$snapshot])) {
            $this->projectCacheHits++;

            return $this->projectFindingsCache[$snapshot];
        }
        $this->projectCacheMisses++;
        $parsed = [];
        $mergeFile = function (string $file) use (&$parsed, &$sources, &$bodies, &$calls): array {
            if (isset($parsed[$file])) {
                return [];
            }
            $parsed[$file] = true;
            $source = $sources[$file];
            $hash = hash('sha256', $source);
            $nodes = $this->parse($source, false);
            $structure = $this->structureCache[$hash] ??= $this->methodStructures($nodes, $source);
            $bodies += $structure['bodies'];
            foreach ($structure['calls'] as $identity => $targets) {
                $calls[$identity] = array_values(array_unique(array_merge($calls[$identity] ?? [], $targets)));
            }

            return $nodes;
        };
        $reachable = [];
        foreach ($sources as $file => $source) {
            $rawCandidate = preg_match('/(?:->|::)\s*(?:statement|unprepared|affectingStatement|raw|select|selectOne|selectResultSets|selectFromWriteConnection|scalar|cursor|exec|query|prepare|execute)\s*\(/', $source) === 1;
            $rawCandidate = $rawCandidate || (preg_match('/(?:DB\s*::|Connection(?:Interface)?|PDO|\$(?:database|connection))/', $source) === 1
                && preg_match('/(?:->|::)\s*(?:insert|update|delete)\s*\(/', $source) === 1);
            $contractCandidate = stripos($source, 'contract') !== false
                && preg_match('/(?:->|::)\s*(?:save|saveQuietly|update|updateQuietly|delete|forceDelete|insert|upsert|increment|decrement|touch|restore|create)\s*\(/', $source) === 1;
            if ($rawCandidate || $contractCandidate) {
                $nodes = $mergeFile($file);
                foreach ($this->findingsFromNodes($nodes, []) as $finding) {
                    $pendingFindings[] = ['file' => $file] + $finding;
                    if (is_string($finding['builder_identity'] ?? null)) {
                        $reachable[] = $finding['builder_identity'];
                    }
                }
            }
        }
        $visited = [];
        while (($identity = array_pop($reachable)) !== null) {
            if (isset($visited[$identity])) {
                continue;
            }
            $visited[$identity] = true;
            $targetFile = $declarations[$identity] ?? $declarations[explode('::', $identity)[0]] ?? null;
            if (is_string($targetFile) && ! isset($parsed[$targetFile])) {
                $mergeFile($targetFile);
            }
            foreach ($calls[$identity] ?? [] as $target) {
                if (! str_starts_with($target, 'dynamic:')) {
                    $reachable[] = $target;
                }
            }
        }
        $methodHashes = $this->stronglyConnectedStructuralHashes($bodies, $calls);
        $findings = [];
        foreach ($pendingFindings as $finding) {
            $builderIdentity = $finding['builder_identity'] ?? null;
            unset($finding['builder_identity']);
            if (is_string($builderIdentity)) {
                $hash = $methodHashes[$builderIdentity] ?? 'unresolved-'.hash('sha256', $builderIdentity);
                $finding['fingerprint'] = (string) preg_replace('/\|builder=pending$/', '|builder='.$hash, $finding['fingerprint']);
            }
            $findings[] = $finding;
        }

        return $this->projectFindingsCache[$snapshot] = $findings;
    }

    /** @return array{hits:int,misses:int} */
    public function projectCacheMetrics(): array
    {
        return ['hits' => $this->projectCacheHits, 'misses' => $this->projectCacheMisses];
    }

    /** @return list<Node> */
    private function parse(string $source, bool $cache = true): array
    {
        $hash = hash('sha256', $source);
        if ($cache && isset($this->parseCache[$hash])) {
            return $this->parseCache[$hash];
        }
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        $nodes = $traverser->traverse($nodes);

        return $cache ? $this->parseCache[$hash] = $nodes : $nodes;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string,string>  $methodHashes
     * @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string,evidence:string}>
     */
    private function findingsFromNodes(array $nodes, array $methodHashes): array
    {
        $finder = new NodeFinder;
        $candidate = $finder->findFirst($nodes, function (Node $node): bool {
            if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
                return in_array($node->name->toString(), array_merge(self::MUTATIONS, self::RAW_SQL_ENTRY_POINTS, self::PDO_ENTRY_POINTS, ['execute']), true);
            }

            return $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), array_merge(self::MUTATIONS, self::RAW_SQL_ENTRY_POINTS, self::PDO_ENTRY_POINTS, ['execute']), true);
        });
        if ($candidate === null) {
            return [];
        }
        $scopes = $finder->findInstanceOf($nodes, Node\FunctionLike::class);
        $repositoryProperties = $this->repositoryProperties($nodes);
        $connectionProperties = $this->typedProperties($nodes, fn (?Node $type): bool => $this->isDatabaseConnectionType($type));
        $pdoProperties = $this->typedProperties($nodes, fn (?Node $type): bool => $this->isPdoType($type));
        $statementProperties = $this->typedProperties($nodes, fn (?Node $type): bool => $this->isPdoStatementType($type));
        $methodReturnKinds = $this->methodReturnKinds($nodes);
        $findings = [];
        foreach ($scopes as $scope) {
            $class = $this->enclosingClassName($nodes, $scope);
            $classIdentity = $this->enclosingClassIdentity($nodes, $scope);
            $method = $this->scopeMethodName($nodes, $scope);
            $inheritedDatabase = $this->inheritedDatabaseState(
                $nodes,
                $scope,
                $connectionProperties[$class] ?? [],
                $pdoProperties[$class] ?? [],
                $statementProperties[$class] ?? [],
                $methodReturnKinds[$class] ?? [],
            );
            $findings = array_merge($findings, $this->scopeFindings(
                $scope,
                $class,
                $method,
                $repositoryProperties[$class] ?? [],
                $connectionProperties[$class] ?? [],
                $pdoProperties[$class] ?? [],
                $statementProperties[$class] ?? [],
                $methodReturnKinds[$class] ?? [],
                $methodHashes,
                $classIdentity,
                $this->inheritedTypedVariables($nodes, $scope, true),
                $this->inheritedTypedVariables($nodes, $scope, false),
                $inheritedDatabase['connection'],
                $inheritedDatabase['pdo'],
                $inheritedDatabase['statement'],
            ));
        }

        return $findings;
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string,evidence:string}> */
    private function scopeFindings(
        Node\FunctionLike $scope,
        string $class,
        string $method,
        array $repositoryProperties,
        array $connectionProperties,
        array $pdoProperties,
        array $statementProperties,
        array $methodReturnKinds,
        array $methodHashes,
        string $classIdentity,
        array $inheritedContractVariables,
        array $inheritedRepositoryVariables,
        array $inheritedConnectionVariables,
        array $inheritedPdoVariables,
        array $inheritedStatementVariables,
    ): array {
        $finder = new NodeFinder;
        $printer = new Standard;
        $contractVariables = $class === 'Contract' ? ['this' => true] : $inheritedContractVariables;
        $repositoryVariables = $inheritedRepositoryVariables;
        $connectionVariables = $inheritedConnectionVariables;
        $pdoVariables = $inheritedPdoVariables;
        $statementVariables = $inheritedStatementVariables;
        foreach ($scope->getParams() as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $this->isExactContractType($parameter->type)) {
                $contractVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isContractRepositoryType($parameter->type)) {
                $repositoryVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isDatabaseConnectionType($parameter->type)) {
                $connectionVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isPdoType($parameter->type)) {
                $pdoVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isPdoStatementType($parameter->type)) {
                $statementVariables[(string) $parameter->var->name] = true;
            }
        }
        $scopeNodes = $this->nodesWithinScope($scope);
        $stringConstants = [];
        $preparedStatements = [];
        $assignments = array_values(array_filter($scopeNodes, static fn (Node $node): bool => $node instanceof Node\Expr\Assign));
        $assignmentCounts = [];
        foreach ($assignments as $assignment) {
            $target = $this->expressionKey($assignment->var, $printer);
            if ($target !== null) {
                $assignmentCounts[$target] = ($assignmentCounts[$target] ?? 0) + 1;
            }
        }
        do {
            $changed = false;
            foreach ($assignments as $assignment) {
                $target = $this->expressionKey($assignment->var, $printer);
                if ($target === null) {
                    continue;
                }
                $name = $target;
                if (! isset($contractVariables[$name]) && $this->isContractExpression($assignment->expr, $contractVariables, $printer)) {
                    $contractVariables[$name] = true;
                    $changed = true;
                }
                if (! isset($repositoryVariables[$name]) && $this->isRepositoryExpression($assignment->expr, $repositoryVariables, $repositoryProperties, $printer)) {
                    $repositoryVariables[$name] = true;
                    $changed = true;
                }
                if (! isset($connectionVariables[$name]) && $this->isConnectionExpression($assignment->expr, $connectionVariables, $connectionProperties)) {
                    $connectionVariables[$name] = true;
                    $changed = true;
                }
                if (! isset($pdoVariables[$name]) && $this->isPdoExpression($assignment->expr, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                    $pdoVariables[$name] = true;
                    $changed = true;
                }
                if (! isset($statementVariables[$name]) && $this->isStatementExpression($assignment->expr, $statementVariables, $statementProperties, $preparedStatements, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties, $methodReturnKinds, $printer)) {
                    $statementVariables[$name] = true;
                    $changed = true;
                }
                if ($assignment->expr instanceof Node\Expr\MethodCall
                    && $assignment->expr->name instanceof Node\Identifier
                    && $assignment->expr->name->toString() === 'prepare'
                    && $this->isPdoExpression($assignment->expr->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                    $preparedStatements[$name] = isset($assignment->expr->args[0])
                        ? $this->constantString($assignment->expr->args[0]->value, $stringConstants)
                        : null;
                }
                $constant = ($assignmentCounts[$name] ?? 0) === 1 ? $this->constantString($assignment->expr, $stringConstants) : null;
                if ($assignment->var instanceof Node\Expr\Variable && $constant !== null && ($stringConstants[$name] ?? null) !== $constant) {
                    $stringConstants[$name] = $constant;
                    $changed = true;
                }
            }
        } while ($changed);

        $results = [];
        foreach (array_filter($scopeNodes, fn (Node $node): bool => $this->isMutation($node, $contractVariables, $repositoryVariables, $repositoryProperties, $connectionVariables, $connectionProperties, $pdoVariables, $pdoProperties, $statementVariables, $statementProperties, $methodReturnKinds, $preparedStatements, $stringConstants, $printer)) as $node) {
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : 'raw_sql';
            $receiver = $node instanceof Node\Expr\MethodCall ? $printer->prettyPrintExpr($node->var) : $printer->prettyPrintExpr($node);
            $databaseExecution = $this->isDatabaseExecutionNode($node, $connectionVariables, $connectionProperties, $pdoVariables, $pdoProperties);
            $builderIdentity = $databaseExecution
                ? ($this->structuralBuilderIdentity($node, $classIdentity) ?? $classIdentity.'::'.$method)
                : null;
            $structuralHash = $builderIdentity === null ? null : ($methodHashes[$builderIdentity] ?? ($methodHashes === [] ? 'pending' : hash('sha256', 'unresolved:'.$builderIdentity)));
            $fingerprint = implode('|', [$class, $method, $operation, preg_replace('/\s+/', '', $receiver)]).($structuralHash === null ? '' : '|builder='.$structuralHash);
            $evidence = $this->findingEvidence($node, $stringConstants, $printer);
            $results[] = ['line' => $node->getStartLine(), 'class' => $class, 'method' => $method, 'operation' => $operation, 'receiver' => $receiver, 'fingerprint' => $fingerprint, 'evidence' => $evidence, 'builder_identity' => $builderIdentity];
        }

        return $results;
    }

    private function isDatabaseExecutionNode(Node $node, array $connectionVariables, array $connectionProperties, array $pdoVariables, array $pdoProperties): bool
    {
        if ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $this->isDatabaseFacadeName($node->class->toString())
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toString(), self::RAW_SQL_ENTRY_POINTS, true)) {
            return true;
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
            return false;
        }
        $operation = $node->name->toString();

        return (in_array($operation, self::RAW_SQL_ENTRY_POINTS, true)
                && ($this->rootedAtDatabaseConnection($node->var) || $this->isConnectionExpression($node->var, $connectionVariables, $connectionProperties)))
            || (in_array($operation, array_merge(self::PDO_ENTRY_POINTS, ['execute']), true)
                && ($this->isPdoExpression($node->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)
                    || $operation === 'execute'));
    }

    /** @param array<string, true> $variables */
    private function isMutation(Node $node, array $variables, array $repositoryVariables, array $repositoryProperties, array $connectionVariables, array $connectionProperties, array $pdoVariables, array $pdoProperties, array $statementVariables, array $statementProperties, array $methodReturnKinds, array $preparedStatements, array $stringConstants, Standard $printer): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : $printer->prettyPrintExpr($node->class);
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            if ($this->isExactContractName($class) && in_array($operation, ['create', 'updateOrCreate', 'firstOrCreate', 'destroy', 'forceDestroy'], true)) {
                return true;
            }
            if ($this->isDatabaseFacadeName($class) && in_array($operation, self::RAW_SQL_ENTRY_POINTS, true)) {
                $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

                return $sql === null || $this->sqlRequiresAudit($sql);
            }
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
            return false;
        }
        $operation = $node->name->toString();
        if (in_array($operation, self::RAW_SQL_ENTRY_POINTS, true)
            && ($this->rootedAtDatabaseConnection($node->var) || $this->isConnectionExpression($node->var, $connectionVariables, $connectionProperties))) {
            $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

            return $sql === null || $this->sqlRequiresAudit($sql);
        }
        if (in_array($operation, self::PDO_ENTRY_POINTS, true)
            && $this->isPdoExpression($node->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
            if ($operation === 'prepare') {
                return false;
            }
            $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

            return $sql === null || $this->sqlRequiresAudit($sql);
        }
        if ($operation === 'execute') {
            if ($node->var instanceof Node\Expr\MethodCall
                && $node->var->name instanceof Node\Identifier
                && $node->var->name->toString() === 'prepare'
                && $this->isPdoExpression($node->var->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                $sql = isset($node->var->args[0]) ? $this->constantString($node->var->args[0]->value, $stringConstants) : null;

                return $sql === null || $this->sqlRequiresAudit($sql);
            }
            $statement = $this->expressionKey($node->var, $printer);
            if ($this->isStatementExpression($node->var, $statementVariables, $statementProperties, $preparedStatements, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties, $methodReturnKinds, $printer)) {
                $sql = $statement !== null && array_key_exists($statement, $preparedStatements) ? $preparedStatements[$statement] : null;

                return $sql === null || $this->sqlRequiresAudit($sql);
            }
        }
        if (! in_array($operation, self::MUTATIONS, true)) {
            return false;
        }
        $receiver = $printer->prettyPrintExpr($node->var);
        $root = $this->rootVariable($node->var);
        $repositoryProperty = $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->var instanceof Node\Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Node\Identifier
            && in_array($node->var->name->toString(), $repositoryProperties, true);

        return ($root !== null && isset($variables[$root]))
            || ($root !== null && isset($repositoryVariables[$root]))
            || $repositoryProperty
            || $this->receiverContainsRepositoryProperty($receiver, $repositoryProperties)
            || $this->rootedAtExactContractStatic($node->var)
            || $this->rootedAtContractsTable($node->var, $connectionVariables, $connectionProperties)
            || str_contains($receiver, 'contractRepository')
            || str_contains($receiver, 'contracts()');
    }

    /** @param array<string, true> $variables */
    private function isContractExpression(Node\Expr $expression, array $variables, Standard $printer): bool
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            return true;
        }
        if ($expression instanceof Node\Expr\PropertyFetch && $expression->name instanceof Node\Identifier && $expression->name->toString() === 'contract') {
            return true;
        }
        $printed = $printer->prettyPrintExpr($expression);

        return $this->rootedAtExactContractStatic($expression)
            || preg_match('/contractRepository->(?:find|findOrFail|getById)/', $printed) === 1
            || preg_match('/->contract\(\)->/', $printed) === 1
            || $this->rootedAtContractsTable($expression, [], []);
    }

    private function rootVariable(Node\Expr $expression): ?string
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Node\Expr\Variable && is_string($expression->name) ? $expression->name : null;
    }

    private function rootedAtExactContractStatic(Node\Expr $expression): bool
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            $expression = $expression->var;
        }
        if (! $expression instanceof Node\Expr\StaticCall || ! $expression->class instanceof Node\Name) {
            return false;
        }

        return $this->isExactContractName($expression->class->toString());
    }

    private function rootedAtDatabaseConnection(Node\Expr $expression): bool
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $this->isDatabaseFacadeName($expression->class->toString())
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === 'connection';
    }

    /** @param array<string, string> $constants */
    private function constantString(Node\Expr $expression, array $constants): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }
        if ($expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_) {
            return (string) $expression->value;
        }
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return $constants[$expression->name] ?? null;
        }
        if ($expression instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->constantString($expression->left, $constants);
            $right = $this->constantString($expression->right, $constants);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        return null;
    }

    /** @return list<Node> */
    private function nodesWithinScope(Node\FunctionLike $scope): array
    {
        $roots = $scope instanceof Node\Expr\ArrowFunction
            ? [$scope->expr]
            : ($scope->getStmts() ?? []);
        $nodes = [];
        $walk = function (Node $node) use (&$walk, &$nodes, $scope): void {
            if ($node instanceof Node\FunctionLike && $node !== $scope) {
                return;
            }
            $nodes[] = $node;
            foreach ($node->getSubNodeNames() as $name) {
                $child = $node->{$name};
                if ($child instanceof Node) {
                    $walk($child);
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if ($item instanceof Node) {
                            $walk($item);
                        }
                    }
                }
            }
        };
        foreach ($roots as $root) {
            $walk($root);
        }

        return $nodes;
    }

    private function sqlRequiresAudit(string $sql): bool
    {
        $identifier = '(?:"contracts"|`contracts`|contracts)';
        $qualifier = '(?:(?:"[^"]+"|`[^`]+`|[a-z_][a-z0-9_$]*)\s*\.\s*)?';
        $mutation = '/\b(?:insert\s+into|update|delete\s+from|merge\s+into|truncate(?:\s+table)?|copy)\s+(?:only\s+)?'.$qualifier.$identifier.'(?![a-z0-9_$])/is';
        $writableFunction = '/\b(?:(?:mutate|update|delete|insert|create|save|upsert)_?contracts?|contracts?_(?:mutate|update|delete|insert|create|save|upsert))\s*\(/i';

        if (preg_match($mutation, $sql) === 1 || preg_match($writableFunction, $sql) === 1) {
            return true;
        }
        if (preg_match('/\b(?:select|with)\b/i', $sql) !== 1) {
            return false;
        }
        preg_match_all('/(?<![a-z0-9_$])(?:(?:"([^"]+)"|([a-z_][a-z0-9_$]*))\s*\.\s*)?(?:"([^"]+)"|([a-z_][a-z0-9_$]*))\s*\(/i', $sql, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $function = strtolower((string) (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '')));
            if ($function !== '' && ! array_key_exists($function, self::SAFE_SQL_FUNCTIONS)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,string> $stringConstants */
    private function findingEvidence(Node $node, array $stringConstants, Standard $printer): string
    {
        $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : 'raw_sql';
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) && isset($node->args[0])) {
            $sql = $this->constantString($node->args[0]->value, $stringConstants);
            if ($sql !== null) {
                $normalized = (string) preg_replace('/\s+/', ' ', trim($sql));
                $snippet = strlen($normalized) > 180 ? substr($normalized, 0, 180).'…' : $normalized;

                return $operation.':sql='.$snippet;
            }

            return $operation.':argument='.(string) preg_replace('/\s+/', '', $printer->prettyPrintExpr($node->args[0]->value));
        }

        return $operation.':receiver='.(string) preg_replace('/\s+/', '', $printer->prettyPrintExpr($node));
    }

    private function isDatabaseFacadeName(string $name): bool
    {
        return ltrim($name, '\\') === 'Illuminate\\Support\\Facades\\DB';
    }

    private function rootedAtContractsTable(Node\Expr $expression, array $connectionVariables, array $connectionProperties): bool
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            if ($expression instanceof Node\Expr\MethodCall
                && $expression->name instanceof Node\Identifier
                && $expression->name->toString() === 'table'
                && isset($expression->args[0])
                && $expression->args[0]->value instanceof Node\Scalar\String_
                && $this->tableBasename($expression->args[0]->value->value) === 'contracts'
                && ($this->rootedAtDatabaseConnection($expression->var) || $this->isConnectionExpression($expression->var, $connectionVariables, $connectionProperties))) {
                return true;
            }
            $expression = $expression->var;
        }
        if (! $expression instanceof Node\Expr\StaticCall
            || ! $expression->class instanceof Node\Name
            || ! $this->isDatabaseFacadeName($expression->class->toString())
            || ! $expression->name instanceof Node\Identifier
            || $expression->name->toString() !== 'table'
            || ! isset($expression->args[0])
            || ! $expression->args[0]->value instanceof Node\Scalar\String_) {
            return false;
        }

        return $this->tableBasename($expression->args[0]->value->value) === 'contracts';
    }

    private function tableBasename(string $table): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($table));
        $basename = (string) end($parts);

        return strtolower(trim($basename, '"`'));
    }

    private function isDatabaseConnectionType(?Node $node): bool
    {
        return $this->typeContains($node, ['Illuminate\\Database\\ConnectionInterface', 'Illuminate\\Database\\Connection']);
    }

    private function isPdoType(?Node $node): bool
    {
        return $this->typeContains($node, ['PDO']);
    }

    private function isPdoStatementType(?Node $node): bool
    {
        return $this->typeContains($node, ['PDOStatement']);
    }

    /** @param list<string> $expected */
    private function typeContains(?Node $type, array $expected): bool
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeContains($type->type, $expected);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $member) {
                if ($this->typeContains($member, $expected)) {
                    return true;
                }
            }

            return false;
        }
        if (! $type instanceof Node\Name) {
            return false;
        }

        return in_array(ltrim($type->toString(), '\\'), $expected, true);
    }

    private function isConnectionExpression(Node\Expr $expression, array $variables, array $properties): bool
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return isset($variables[$expression->name]);
        }
        if ($expression instanceof Node\Expr\PropertyFetch
            && $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier) {
            return in_array($expression->name->toString(), $properties, true);
        }
        if ($expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && $this->isDatabaseFacadeName($expression->class->toString())
            && $expression->name instanceof Node\Identifier
            && $expression->name->toString() === 'connection') {
            return true;
        }

        return false;
    }

    private function isPdoExpression(Node\Expr $expression, array $pdoVariables, array $pdoProperties, array $connectionVariables, array $connectionProperties): bool
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return isset($pdoVariables[$expression->name]);
        }
        if ($expression instanceof Node\Expr\PropertyFetch
            && $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier) {
            return in_array($expression->name->toString(), $pdoProperties, true);
        }
        if ($expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && ltrim($expression->class->toString(), '\\') === 'PDO') {
            return true;
        }
        if ($expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && in_array($expression->name->toString(), ['getPdo', 'getRawPdo'], true)) {
            return $this->isConnectionExpression($expression->var, $connectionVariables, $connectionProperties)
                || $this->rootedAtDatabaseConnection($expression->var);
        }

        return false;
    }

    private function isStatementExpression(Node\Expr $expression, array $statementVariables, array $statementProperties, array $preparedStatements, array $pdoVariables, array $pdoProperties, array $connectionVariables, array $connectionProperties, array $methodReturnKinds, Standard $printer): bool
    {
        $key = $this->expressionKey($expression, $printer);
        if ($key !== null && (isset($statementVariables[$key]) || array_key_exists($key, $preparedStatements))) {
            return true;
        }
        if ($expression instanceof Node\Expr\PropertyFetch
            && $expression->var instanceof Node\Expr\Variable
            && $expression->var->name === 'this'
            && $expression->name instanceof Node\Identifier
            && in_array($expression->name->toString(), $statementProperties, true)) {
            return true;
        }
        if ($expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier) {
            if ($expression->name->toString() === 'prepare'
                && $this->isPdoExpression($expression->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                return true;
            }
            if ($expression->var instanceof Node\Expr\Variable
                && $expression->var->name === 'this'
                && ($methodReturnKinds[$expression->name->toString()] ?? null) === 'statement') {
                return true;
            }
        }

        return false;
    }

    private function expressionKey(Node\Expr $expression, Standard $printer): ?string
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return $expression->name;
        }
        if ($expression instanceof Node\Expr\ArrayDimFetch || $expression instanceof Node\Expr\PropertyFetch) {
            return (string) preg_replace('/\s+/', '', $printer->prettyPrintExpr($expression));
        }

        return null;
    }

    private function isExactContractType(?Node $node): bool
    {
        return $node instanceof Node\Name && $this->isExactContractName($node->toString());
    }

    private function isExactContractName(string $name): bool
    {
        $name = ltrim($name, '\\');

        return $name === 'Contract' || $name === 'App\\Models\\Contract';
    }

    private function isContractRepositoryType(?Node $node): bool
    {
        return $node instanceof Node\Name && str_starts_with($this->shortName($node->toString()), 'ContractRepository');
    }

    /** @param list<string> $properties */
    private function receiverContainsRepositoryProperty(string $receiver, array $properties): bool
    {
        foreach ($properties as $property) {
            if (str_contains($receiver, '$this->'.$property)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $variables @param list<string> $properties */
    private function isRepositoryExpression(Node\Expr $expression, array $variables, array $properties, Standard $printer): bool
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && isset($variables[$expression->name])) {
            return true;
        }
        if ($expression instanceof Node\Expr\PropertyFetch && $expression->name instanceof Node\Identifier && in_array($expression->name->toString(), $properties, true)) {
            return true;
        }

        return str_contains($printer->prettyPrintExpr($expression), 'contractRepository');
    }

    /** @param list<Node> $nodes @return array<string, list<string>> */
    private function typedProperties(array $nodes, callable $matchesType): array
    {
        $properties = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            $className = $class->name?->toString() ?? 'anonymous';
            foreach ($class->getProperties() as $property) {
                if ($matchesType($property->type)) {
                    foreach ($property->props as $item) {
                        $properties[$className][] = $item->name->toString();
                    }
                }
            }
            foreach ($class->getMethod('__construct')?->getParams() ?? [] as $parameter) {
                if ($parameter->flags !== 0
                    && $parameter->var instanceof Node\Expr\Variable
                    && is_string($parameter->var->name)
                    && $matchesType($parameter->type)) {
                    $properties[$className][] = $parameter->var->name;
                }
            }
        }

        return $properties;
    }

    /** @param list<Node> $nodes @return array<string,array<string,string>> */
    private function methodReturnKinds(array $nodes): array
    {
        $kinds = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            $className = $class->name?->toString() ?? 'anonymous';
            foreach ($class->getMethods() as $method) {
                $kind = $this->databaseTypeKind($method->returnType);
                if ($kind !== null) {
                    $kinds[$className][$method->name->toString()] = $kind;
                }
            }
        }

        return $kinds;
    }

    private function databaseTypeKind(?Node $type): ?string
    {
        return match (true) {
            $this->isDatabaseConnectionType($type) => 'connection',
            $this->isPdoType($type) => 'pdo',
            $this->isPdoStatementType($type) => 'statement',
            default => null,
        };
    }

    /** @param list<Node> $nodes @return array<string,string> */
    private function methodStructuralHashes(array $nodes): array
    {
        $structure = $this->methodStructures($nodes);

        return $this->stronglyConnectedStructuralHashes($structure['bodies'], $structure['calls']);
    }

    /** @param list<Node> $nodes @return array{bodies:array<string,string>,calls:array<string,list<string>>} */
    private function methodStructures(array $nodes, ?string $source = null): array
    {
        $printer = new Standard;
        $bodies = [];
        $calls = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $classNode) {
            $class = $this->declaredClassIdentity($classNode);
            foreach ($classNode->getMethods() as $method) {
                $this->collectMethodStructure($method, $class, $printer, $bodies, $calls, null, $source);
            }
        }
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
            $namespaceName = $function->namespacedName ?? $function->getAttribute('namespacedName');
            $identity = $namespaceName instanceof Node\Name ? $namespaceName->toString() : $function->name->toString();
            $this->collectMethodStructure($function, 'function', $printer, $bodies, $calls, $identity, $source);
        }
        foreach ($calls as $identity => $targets) {
            $calls[$identity] = array_values(array_unique($targets));
            sort($calls[$identity], SORT_STRING);
        }

        return ['bodies' => $bodies, 'calls' => $calls];
    }

    /**
     * @param  array<string,string>  $bodies
     * @param  array<string,list<string>>  $calls
     * @return array<string,string>
     */
    private function stronglyConnectedStructuralHashes(array $bodies, array $calls): array
    {
        $identities = array_keys($bodies);
        sort($identities, SORT_STRING);
        $nextIndex = 0;
        $indices = [];
        $lowLinks = [];
        $stack = [];
        $onStack = [];
        $components = [];
        $visit = function (string $identity) use (&$visit, &$nextIndex, &$indices, &$lowLinks, &$stack, &$onStack, &$components, $bodies, $calls): void {
            $indices[$identity] = $nextIndex;
            $lowLinks[$identity] = $nextIndex++;
            $stack[] = $identity;
            $onStack[$identity] = true;
            foreach ($calls[$identity] ?? [] as $target) {
                if (! isset($bodies[$target])) {
                    continue;
                }
                if (! array_key_exists($target, $indices)) {
                    $visit($target);
                    $lowLinks[$identity] = min($lowLinks[$identity], $lowLinks[$target]);
                } elseif (isset($onStack[$target])) {
                    $lowLinks[$identity] = min($lowLinks[$identity], $indices[$target]);
                }
            }
            if ($lowLinks[$identity] !== $indices[$identity]) {
                return;
            }
            $component = [];
            do {
                $member = array_pop($stack);
                if (! is_string($member)) {
                    break;
                }
                unset($onStack[$member]);
                $component[] = $member;
            } while ($member !== $identity);
            sort($component, SORT_STRING);
            $components[] = $component;
        };
        foreach ($identities as $identity) {
            if (! array_key_exists($identity, $indices)) {
                $visit($identity);
            }
        }
        $componentByMember = [];
        foreach ($components as $componentId => $members) {
            foreach ($members as $member) {
                $componentByMember[$member] = $componentId;
            }
        }
        $memo = [];
        $resolve = function (int $componentId) use (&$resolve, &$memo, $components, $componentByMember, $bodies, $calls): string {
            if (isset($memo[$componentId])) {
                return $memo[$componentId];
            }
            $members = [];
            $outgoing = [];
            foreach ($components[$componentId] as $member) {
                $members[$member] = $bodies[$member];
                foreach ($calls[$member] ?? [] as $target) {
                    if (! isset($componentByMember[$target])) {
                        $outgoing['external:'.$target] = hash('sha256', 'external:'.$target);

                        continue;
                    }
                    $targetComponent = $componentByMember[$target];
                    if ($targetComponent !== $componentId) {
                        $outgoing['scc:'.implode('|', $components[$targetComponent])] = $resolve($targetComponent);
                    }
                }
            }
            ksort($members, SORT_STRING);
            ksort($outgoing, SORT_STRING);

            return $memo[$componentId] = hash('sha256', json_encode(['members' => $members, 'outgoing' => $outgoing], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        };
        $hashes = [];
        foreach ($componentByMember as $member => $componentId) {
            $hashes[$member] = $resolve($componentId);
        }

        return $hashes;
    }

    private function collectMethodStructure(Node\Stmt\ClassMethod|Node\Stmt\Function_ $method, string $class, Standard $printer, array &$bodies, array &$calls, ?string $functionIdentity = null, ?string $source = null): void
    {
        $name = $method->name->toString();
        $identity = $functionIdentity ?? $class.'::'.$name;
        $bodies[$identity] = $source === null
            ? $printer->prettyPrint($method->getStmts() ?? [])
            : substr($source, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1);
        foreach ($this->nodesWithinScope($method) as $node) {
            if ($node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier) {
                $calls[$identity][] = $class.'::'.$node->name->toString();
            } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
                $calls[$identity][] = 'dynamic:'.preg_replace('/\s+/', '', $printer->prettyPrintExpr($node->var)).'::'.$node->name->toString();
            }
            if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
                $target = $node->class instanceof Node\Name ? $node->class->toString() : $printer->prettyPrintExpr($node->class);
                if (in_array(strtolower($target), ['self', 'static'], true)) {
                    $target = $class;
                }
                $calls[$identity][] = ltrim($target, '\\').'::'.$node->name->toString();
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $calls[$identity][] = ltrim($node->name->toString(), '\\');
            }
        }
    }

    private function structuralBuilderIdentity(Node $node, string $classIdentity): ?string
    {
        if (! ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            || ! isset($node->args[0])) {
            return null;
        }
        $argument = $node->args[0]->value;
        if ($argument instanceof Node\Expr\MethodCall
            && $argument->var instanceof Node\Expr\Variable
            && $argument->var->name === 'this'
            && $argument->name instanceof Node\Identifier) {
            return $classIdentity.'::'.$argument->name->toString();
        }
        if ($argument instanceof Node\Expr\StaticCall
            && $argument->class instanceof Node\Name
            && $argument->name instanceof Node\Identifier) {
            $target = $argument->class->toString();
            if (in_array(strtolower($target), ['self', 'static'], true)) {
                $target = $classIdentity;
            }

            return ltrim($target, '\\').'::'.$argument->name->toString();
        }
        if ($argument instanceof Node\Expr\FuncCall && $argument->name instanceof Node\Name) {
            return ltrim($argument->name->toString(), '\\');
        }
        if ($argument instanceof Node\Expr\MethodCall || $argument instanceof Node\Expr\StaticCall || $argument instanceof Node\Expr\FuncCall) {
            return 'unresolved-expression:'.(string) preg_replace('/\s+/', '', (new Standard)->prettyPrintExpr($argument));
        }

        return null;
    }

    /** @param list<Node> $nodes @return array<string, list<string>> */
    private function repositoryProperties(array $nodes): array
    {
        $properties = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            $className = $class->name?->toString() ?? 'anonymous';
            foreach ($class->getProperties() as $property) {
                if ($this->isContractRepositoryType($property->type)) {
                    foreach ($property->props as $item) {
                        $properties[$className][] = $item->name->toString();
                    }
                }
            }
            $constructor = $class->getMethod('__construct');
            foreach ($constructor?->getParams() ?? [] as $parameter) {
                if ($parameter->flags !== 0 && $parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name) && $this->isContractRepositoryType($parameter->type)) {
                    $properties[$className][] = $parameter->var->name;
                }
            }
        }

        return $properties;
    }

    private function shortName(string $name): string
    {
        $parts = explode('\\', ltrim($name, '\\'));

        return (string) end($parts);
    }

    /** @param list<Node> $nodes */
    private function scopeMethodName(array $nodes, Node\FunctionLike $scope): string
    {
        if ($scope instanceof Node\Stmt\ClassMethod || $scope instanceof Node\Stmt\Function_) {
            return $scope->name->toString();
        }
        $parent = $this->enclosingNamedFunction($nodes, $scope);
        if ($parent !== null) {
            return $parent->name->toString();
        }

        return ($scope instanceof Node\Expr\Closure ? 'closure@' : 'arrow@').$scope->getStartLine();
    }

    /** @param list<Node> $nodes @return array<string,true> */
    private function inheritedTypedVariables(array $nodes, Node\FunctionLike $scope, bool $contracts): array
    {
        if ($scope instanceof Node\Stmt\ClassMethod || $scope instanceof Node\Stmt\Function_) {
            return [];
        }
        $parent = $this->enclosingFunctionLike($nodes, $scope);
        $variables = $parent === null ? [] : $this->inheritedTypedVariables($nodes, $parent, $contracts);
        foreach ($parent?->getParams() ?? [] as $parameter) {
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }
            if (($contracts && $this->isExactContractType($parameter->type))
                || (! $contracts && $this->isContractRepositoryType($parameter->type))) {
                $variables[$parameter->var->name] = true;
            }
        }

        return $variables;
    }

    /** @param list<Node> $nodes @return array{connection:array<string,true>,pdo:array<string,true>,statement:array<string,true>} */
    private function inheritedDatabaseState(array $nodes, Node\FunctionLike $scope, array $connectionProperties, array $pdoProperties, array $statementProperties, array $returnKinds): array
    {
        if ($scope instanceof Node\Stmt\ClassMethod || $scope instanceof Node\Stmt\Function_) {
            return ['connection' => [], 'pdo' => [], 'statement' => []];
        }
        $parent = $this->enclosingFunctionLike($nodes, $scope);
        $inherited = $parent === null
            ? ['connection' => [], 'pdo' => [], 'statement' => []]
            : $this->inheritedDatabaseState($nodes, $parent, $connectionProperties, $pdoProperties, $statementProperties, $returnKinds);
        $connectionVariables = $inherited['connection'];
        $pdoVariables = $inherited['pdo'];
        $statementVariables = $inherited['statement'];
        foreach ($parent?->getParams() ?? [] as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable
                && is_string($parameter->var->name)) {
                $parameterKind = $this->databaseTypeKind($parameter->type);
                if ($parameterKind === 'connection') {
                    $connectionVariables[$parameter->var->name] = true;
                } elseif ($parameterKind === 'pdo') {
                    $pdoVariables[$parameter->var->name] = true;
                } elseif ($parameterKind === 'statement') {
                    $statementVariables[$parameter->var->name] = true;
                }
            }
        }
        if ($parent === null) {
            return ['connection' => [], 'pdo' => [], 'statement' => []];
        }
        $printer = new Standard;
        $prepared = [];
        $assignments = array_filter($this->nodesWithinScope($parent), static fn (Node $node): bool => $node instanceof Node\Expr\Assign);
        do {
            $changed = false;
            foreach ($assignments as $assignment) {
                $target = $this->expressionKey($assignment->var, $printer);
                if ($target === null) {
                    continue;
                }
                if (! isset($connectionVariables[$target]) && $this->isConnectionExpression($assignment->expr, $connectionVariables, $connectionProperties)) {
                    $connectionVariables[$target] = true;
                    $changed = true;
                }
                if (! isset($pdoVariables[$target]) && $this->isPdoExpression($assignment->expr, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                    $pdoVariables[$target] = true;
                    $changed = true;
                }
                if ($assignment->expr instanceof Node\Expr\MethodCall
                    && $assignment->expr->name instanceof Node\Identifier
                    && $assignment->expr->name->toString() === 'prepare') {
                    $prepared[$target] = null;
                }
                if (! isset($statementVariables[$target]) && $this->isStatementExpression($assignment->expr, $statementVariables, $statementProperties, $prepared, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties, $returnKinds, $printer)) {
                    $statementVariables[$target] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        return ['connection' => $connectionVariables, 'pdo' => $pdoVariables, 'statement' => $statementVariables];
    }

    /** @param list<Node> $nodes */
    private function enclosingNamedFunction(array $nodes, Node\FunctionLike $scope): Node\Stmt\ClassMethod|Node\Stmt\Function_|null
    {
        $candidate = null;
        foreach ((new NodeFinder)->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) as $function) {
            if ($function->getStartFilePos() <= $scope->getStartFilePos() && $function->getEndFilePos() >= $scope->getEndFilePos()) {
                if ($candidate === null || $function->getStartFilePos() >= $candidate->getStartFilePos()) {
                    $candidate = $function;
                }
            }
        }

        return $candidate;
    }

    /** @param list<Node> $nodes */
    private function enclosingFunctionLike(array $nodes, Node\FunctionLike $scope): ?Node\FunctionLike
    {
        $candidate = null;
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\FunctionLike::class) as $function) {
            if ($function === $scope) {
                continue;
            }
            if ($function->getStartFilePos() <= $scope->getStartFilePos() && $function->getEndFilePos() >= $scope->getEndFilePos()) {
                if ($candidate === null || $function->getStartFilePos() >= $candidate->getStartFilePos()) {
                    $candidate = $function;
                }
            }
        }

        return $candidate;
    }

    /** @param list<Node> $nodes */
    private function enclosingClassName(array $nodes, Node $scope): string
    {
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            if ($class->getStartFilePos() <= $scope->getStartFilePos() && $class->getEndFilePos() >= $scope->getEndFilePos()) {
                return $class->name?->toString() ?? 'anonymous';
            }
        }

        return 'global';
    }

    /** @param list<Node> $nodes */
    private function enclosingClassIdentity(array $nodes, Node $scope): string
    {
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            if ($class->getStartFilePos() <= $scope->getStartFilePos() && $class->getEndFilePos() >= $scope->getEndFilePos()) {
                return $this->declaredClassIdentity($class);
            }
        }

        return 'global';
    }

    private function declaredClassIdentity(Node\Stmt\Class_ $class): string
    {
        $namespacedName = $class->namespacedName ?? $class->getAttribute('namespacedName');

        return $namespacedName instanceof Node\Name
            ? $namespacedName->toString()
            : ($class->name?->toString() ?? 'anonymous');
    }
}
