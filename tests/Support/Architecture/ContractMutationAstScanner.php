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

    private const RAW_SQL_MUTATIONS = ['statement', 'unprepared', 'insert', 'update', 'delete', 'affectingStatement', 'raw'];

    /** @return list<int> */
    public function scan(string $source): array
    {
        return array_values(array_unique(array_column($this->findings($source), 'line')));
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    public function findings(string $source): array
    {
        $contractContext = preg_match('/(?:\bContract\b|\bcontracts?\b|ContractRepository|contractRepository)/', $source) === 1;
        $mutation = preg_match('/\b(?:save|saveQuietly|update|updateQuietly|delete|forceDelete|insert|upsert|increment|decrement|touch|restore|create|updateOrCreate|firstOrCreate|destroy|forceDestroy)\b/i', $source) === 1;
        $rawSqlCall = preg_match('/\bDB::(?:connection\([^)]*\)->)?(?:statement|unprepared|insert|update|delete|affectingStatement|raw)\s*\(/', $source) === 1;
        if ((! $contractContext || ! $mutation) && ! $rawSqlCall) {
            return [];
        }
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        $finder = new NodeFinder;
        $scopes = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_);
        $repositoryProperties = $this->repositoryProperties($nodes);
        $findings = [];
        foreach ($scopes as $scope) {
            $class = $this->enclosingClassName($nodes, $scope);
            $method = $scope->name->toString();
            $findings = array_merge($findings, $this->scopeFindings($scope, $class, $method, $repositoryProperties[$class] ?? []));
        }

        return $findings;
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    private function scopeFindings(Node $scope, string $class, string $method, array $repositoryProperties): array
    {
        $finder = new NodeFinder;
        $printer = new Standard;
        $contractVariables = $class === 'Contract' ? ['this' => true] : [];
        $repositoryVariables = [];
        foreach ($scope->getParams() as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $this->isExactContractType($parameter->type)) {
                $contractVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isContractRepositoryType($parameter->type)) {
                $repositoryVariables[(string) $parameter->var->name] = true;
            }
        }
        foreach ($finder->findInstanceOf($scope->getStmts() ?? [], Node\Param::class) as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $this->isExactContractType($parameter->type)) {
                $contractVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isContractRepositoryType($parameter->type)) {
                $repositoryVariables[(string) $parameter->var->name] = true;
            }
        }
        $stringConstants = [];
        $assignments = $finder->findInstanceOf($scope->getStmts() ?? [], Node\Expr\Assign::class);
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
                $constant = $assignmentCounts[$name] === 1 ? $this->constantString($assignment->expr, $stringConstants) : null;
                if ($constant !== null && ($stringConstants[$name] ?? null) !== $constant) {
                    $stringConstants[$name] = $constant;
                    $changed = true;
                }
            }
        } while ($changed);

        $results = [];
        foreach ($finder->find($scope->getStmts() ?? [], fn (Node $node): bool => $this->isMutation($node, $contractVariables, $repositoryVariables, $repositoryProperties, $stringConstants, $printer)) as $node) {
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : 'raw_sql';
            $receiver = $node instanceof Node\Expr\MethodCall ? $printer->prettyPrintExpr($node->var) : $printer->prettyPrintExpr($node);
            $fingerprint = implode('|', [$class, $method, $operation, preg_replace('/\s+/', '', $receiver)]);
            $results[] = ['line' => $node->getStartLine(), 'class' => $class, 'method' => $method, 'operation' => $operation, 'receiver' => $receiver, 'fingerprint' => $fingerprint];
        }

        return $results;
    }

    /** @param array<string, true> $variables */
    private function isMutation(Node $node, array $variables, array $repositoryVariables, array $repositoryProperties, array $stringConstants, Standard $printer): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : $printer->prettyPrintExpr($node->class);
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            if ($this->isExactContractName($class) && in_array($operation, ['create', 'updateOrCreate', 'firstOrCreate', 'destroy', 'forceDestroy'], true)) {
                return true;
            }
            if ($this->shortName($class) === 'DB' && in_array($operation, self::RAW_SQL_MUTATIONS, true)) {
                $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

                return $sql === null || preg_match('/\b(insert\s+into|update|delete\s+from)\s+["`]?contracts\b/i', $sql) === 1;
            }
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
            return false;
        }
        $operation = $node->name->toString();
        if (in_array($operation, self::RAW_SQL_MUTATIONS, true) && $this->rootedAtDatabaseConnection($node->var)) {
            $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

            return $sql === null || preg_match('/\b(insert\s+into|update|delete\s+from)\s+["`]?contracts\b/i', $sql) === 1;
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
            || preg_match('/DB::(?:connection\([^)]*\)->)?table\([\'"]contracts[\'"]\)/', $receiver) === 1
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
            || preg_match('/DB::(?:connection\([^)]*\)->)?table\([\'"]contracts[\'"]\)/', $printed) === 1;
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
            && $this->shortName($expression->class->toString()) === 'DB'
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
