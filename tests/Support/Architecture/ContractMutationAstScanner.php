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

    /** @return list<int> */
    public function scan(string $source): array
    {
        return array_values(array_unique(array_column($this->findings($source), 'line')));
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    public function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);
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
            unset($nodes, $traverser, $finder, $candidate);
            gc_collect_cycles();

            return [];
        }
        $scopes = $finder->findInstanceOf($nodes, Node\FunctionLike::class);
        $repositoryProperties = $this->repositoryProperties($nodes);
        $connectionProperties = $this->typedProperties($nodes, fn (?Node $type): bool => $this->isDatabaseConnectionType($type));
        $pdoProperties = $this->typedProperties($nodes, fn (?Node $type): bool => $this->isPdoType($type));
        $needsStructuralHashes = $finder->findFirst($nodes, static function (Node $node): bool {
            return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), array_merge(self::RAW_SQL_ENTRY_POINTS, self::PDO_ENTRY_POINTS, ['execute']), true);
        }) !== null;
        $methodHashes = $needsStructuralHashes ? $this->methodStructuralHashes($nodes) : [];
        $findings = [];
        foreach ($scopes as $scope) {
            $class = $this->enclosingClassName($nodes, $scope);
            $method = $this->scopeMethodName($nodes, $scope);
            $findings = array_merge($findings, $this->scopeFindings(
                $scope,
                $class,
                $method,
                $repositoryProperties[$class] ?? [],
                $connectionProperties[$class] ?? [],
                $pdoProperties[$class] ?? [],
                $methodHashes[$class] ?? [],
                $this->inheritedTypedVariables($nodes, $scope, true),
                $this->inheritedTypedVariables($nodes, $scope, false),
            ));
        }

        unset($nodes, $traverser, $finder, $scopes, $repositoryProperties, $connectionProperties, $pdoProperties, $methodHashes);
        gc_collect_cycles();

        return $findings;
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    private function scopeFindings(
        Node\FunctionLike $scope,
        string $class,
        string $method,
        array $repositoryProperties,
        array $connectionProperties,
        array $pdoProperties,
        array $methodHashes,
        array $inheritedContractVariables,
        array $inheritedRepositoryVariables,
    ): array {
        $finder = new NodeFinder;
        $printer = new Standard;
        $contractVariables = $class === 'Contract' ? ['this' => true] : $inheritedContractVariables;
        $repositoryVariables = $inheritedRepositoryVariables;
        $connectionVariables = [];
        $pdoVariables = [];
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
        }
        $scopeNodes = $this->nodesWithinScope($scope);
        $stringConstants = [];
        $preparedStatements = [];
        $assignments = array_values(array_filter($scopeNodes, static fn (Node $node): bool => $node instanceof Node\Expr\Assign));
        $assignmentCounts = [];
        foreach ($assignments as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                $assignmentCounts[$assignment->var->name] = ($assignmentCounts[$assignment->var->name] ?? 0) + 1;
            }
        }
        do {
            $changed = false;
            foreach ($assignments as $assignment) {
                if (! $assignment->var instanceof Node\Expr\Variable || ! is_string($assignment->var->name)) {
                    continue;
                }
                $name = $assignment->var->name;
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
                if ($assignment->expr instanceof Node\Expr\MethodCall
                    && $assignment->expr->name instanceof Node\Identifier
                    && $assignment->expr->name->toString() === 'prepare'
                    && $this->isPdoExpression($assignment->expr->var, $pdoVariables, $pdoProperties, $connectionVariables, $connectionProperties)) {
                    $preparedStatements[$name] = isset($assignment->expr->args[0])
                        ? $this->constantString($assignment->expr->args[0]->value, $stringConstants)
                        : null;
                }
                $constant = $assignmentCounts[$name] === 1 ? $this->constantString($assignment->expr, $stringConstants) : null;
                if ($constant !== null && ($stringConstants[$name] ?? null) !== $constant) {
                    $stringConstants[$name] = $constant;
                    $changed = true;
                }
            }
        } while ($changed);

        $results = [];
        foreach (array_filter($scopeNodes, fn (Node $node): bool => $this->isMutation($node, $contractVariables, $repositoryVariables, $repositoryProperties, $connectionVariables, $connectionProperties, $pdoVariables, $pdoProperties, $preparedStatements, $stringConstants, $printer)) as $node) {
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : 'raw_sql';
            $receiver = $node instanceof Node\Expr\MethodCall ? $printer->prettyPrintExpr($node->var) : $printer->prettyPrintExpr($node);
            $databaseExecution = $this->isDatabaseExecutionNode($node, $connectionVariables, $connectionProperties, $pdoVariables, $pdoProperties);
            $structuralHash = $databaseExecution
                ? ($this->structuralBuilderHash($node, $methodHashes) ?? ($methodHashes[$method] ?? null))
                : null;
            $fingerprint = implode('|', [$class, $method, $operation, preg_replace('/\s+/', '', $receiver)]).($structuralHash === null ? '' : '|builder='.$structuralHash);
            $results[] = ['line' => $node->getStartLine(), 'class' => $class, 'method' => $method, 'operation' => $operation, 'receiver' => $receiver, 'fingerprint' => $fingerprint];
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
    private function isMutation(Node $node, array $variables, array $repositoryVariables, array $repositoryProperties, array $connectionVariables, array $connectionProperties, array $pdoVariables, array $pdoProperties, array $preparedStatements, array $stringConstants, Standard $printer): bool
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
            $root = $this->rootVariable($node->var);
            if ($root !== null && array_key_exists($root, $preparedStatements)) {
                $sql = $preparedStatements[$root];

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
        preg_match_all('/(?<![a-z0-9_$])([a-z_][a-z0-9_$]*)(?:\s*\.\s*([a-z_][a-z0-9_$]*))?\s*\(/i', $sql, $matches, PREG_SET_ORDER);
        $allowed = [
            'abs', 'acos', 'array', 'array_agg', 'avg', 'cast', 'ceil', 'coalesce', 'concat', 'concat_ws', 'cos', 'count',
            'current_setting', 'date_trunc', 'dense_rank', 'encode', 'extract', 'floor', 'greatest',
            'json_agg', 'json_build_array', 'json_build_object', 'jsonb_agg', 'jsonb_build_array',
            'jsonb_build_object', 'lag', 'lead', 'least', 'lower', 'max', 'min', 'nextval', 'now',
            'nullif', 'percentile_cont', 'rank', 'round', 'row_number', 'set_config', 'setval', 'sha256',
            'string_agg', 'sum', 'to_char', 'upper', 'convert_to', 'exists', 'hashtext', 'hashtextextended',
            'radians', 'sin', 'then', 'else', 'when', 'in', 'over', 'values',
        ];
        foreach ($matches as $match) {
            $qualified = $match[2] ?? '';
            $function = strtolower($qualified !== '' ? $qualified : $match[1]);
            if (! in_array($function, $allowed, true) && ! str_starts_with($function, 'pg_')) {
                return true;
            }
        }

        return false;
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
        if (! $node instanceof Node\Name) {
            return false;
        }
        $name = ltrim($node->toString(), '\\');

        return in_array($name, ['Illuminate\\Database\\ConnectionInterface', 'Illuminate\\Database\\Connection'], true);
    }

    private function isPdoType(?Node $node): bool
    {
        return $node instanceof Node\Name && ltrim($node->toString(), '\\') === 'PDO';
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
    private function methodStructuralHashes(array $nodes): array
    {
        $printer = new Standard;
        $bodies = [];
        $calls = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Node\Stmt\Class_::class) as $classNode) {
            $class = $classNode->name?->toString() ?? 'anonymous';
            foreach ($classNode->getMethods() as $method) {
                $this->collectMethodStructure($method, $class, $printer, $bodies, $calls);
            }
        }
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Function_) {
                $this->collectMethodStructure($node, 'global', $printer, $bodies, $calls);
            }
        }
        $hashes = [];
        foreach ($bodies as $class => $methods) {
            foreach ($methods as $name => $body) {
                $children = [];
                foreach (array_unique($calls[$class][$name] ?? []) as $called) {
                    if (isset($methods[$called])) {
                        $children[$called] = hash('sha256', $methods[$called]);
                    }
                }
                ksort($children, SORT_STRING);
                $hashes[$class][$name] = hash('sha256', $body.'|'.json_encode($children, JSON_THROW_ON_ERROR));
            }
        }

        return $hashes;
    }

    private function collectMethodStructure(Node\Stmt\ClassMethod|Node\Stmt\Function_ $method, string $class, Standard $printer, array &$bodies, array &$calls): void
    {
        $name = $method->name->toString();
        $bodies[$class][$name] = $printer->prettyPrint($method->getStmts() ?? []);
        foreach ($this->nodesWithinScope($method) as $node) {
            if ($node instanceof Node\Expr\MethodCall
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Node\Identifier) {
                $calls[$class][$name][] = $node->name->toString();
            }
            if ($class === 'global' && $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $calls[$class][$name][] = $node->name->toString();
            }
        }
    }

    /** @param array<string,string> $methodHashes */
    private function structuralBuilderHash(Node $node, array $methodHashes): ?string
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
            return $methodHashes[$argument->name->toString()] ?? null;
        }
        if ($argument instanceof Node\Expr\FuncCall && $argument->name instanceof Node\Name) {
            return $methodHashes[$argument->name->toString()] ?? null;
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
        $parent = $this->enclosingNamedFunction($nodes, $scope);
        $variables = [];
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
}
