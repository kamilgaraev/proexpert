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
                return in_array($node->name->toString(), array_merge(self::MUTATIONS, self::RAW_SQL_ENTRY_POINTS), true);
            }

            return $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), array_merge(self::MUTATIONS, self::RAW_SQL_ENTRY_POINTS), true);
        });
        if ($candidate === null) {
            return [];
        }
        $scopes = $finder->findInstanceOf($nodes, Node\FunctionLike::class);
        $repositoryProperties = $this->repositoryProperties($nodes);
        $findings = [];
        foreach ($scopes as $scope) {
            $class = $this->enclosingClassName($nodes, $scope);
            $method = $this->scopeMethodName($nodes, $scope);
            $findings = array_merge($findings, $this->scopeFindings(
                $scope,
                $class,
                $method,
                $repositoryProperties[$class] ?? [],
                $this->inheritedTypedVariables($nodes, $scope, true),
                $this->inheritedTypedVariables($nodes, $scope, false),
            ));
        }

        return $findings;
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    private function scopeFindings(
        Node\FunctionLike $scope,
        string $class,
        string $method,
        array $repositoryProperties,
        array $inheritedContractVariables,
        array $inheritedRepositoryVariables,
    ): array {
        $finder = new NodeFinder;
        $printer = new Standard;
        $contractVariables = $class === 'Contract' ? ['this' => true] : $inheritedContractVariables;
        $repositoryVariables = $inheritedRepositoryVariables;
        foreach ($scope->getParams() as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $this->isExactContractType($parameter->type)) {
                $contractVariables[(string) $parameter->var->name] = true;
            }
            if ($parameter->var instanceof Node\Expr\Variable && $this->isContractRepositoryType($parameter->type)) {
                $repositoryVariables[(string) $parameter->var->name] = true;
            }
        }
        $scopeNodes = $this->nodesWithinScope($scope);
        $stringConstants = [];
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
                $constant = $assignmentCounts[$name] === 1 ? $this->constantString($assignment->expr, $stringConstants) : null;
                if ($constant !== null && ($stringConstants[$name] ?? null) !== $constant) {
                    $stringConstants[$name] = $constant;
                    $changed = true;
                }
            }
        } while ($changed);

        $results = [];
        foreach (array_filter($scopeNodes, fn (Node $node): bool => $this->isMutation($node, $contractVariables, $repositoryVariables, $repositoryProperties, $stringConstants, $printer)) as $node) {
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
            if ($this->isDatabaseFacadeName($class) && in_array($operation, self::RAW_SQL_ENTRY_POINTS, true)) {
                $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

                return $sql === null || $this->sqlMutatesContracts($sql);
            }
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
            return false;
        }
        $operation = $node->name->toString();
        if (in_array($operation, self::RAW_SQL_ENTRY_POINTS, true) && $this->rootedAtDatabaseConnection($node->var)) {
            $sql = isset($node->args[0]) ? $this->constantString($node->args[0]->value, $stringConstants) : null;

            return $sql === null || $this->sqlMutatesContracts($sql);
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
            || $this->rootedAtContractsTable($node->var)
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
            || $this->rootedAtContractsTable($expression);
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

    private function sqlMutatesContracts(string $sql): bool
    {
        $identifier = '(?:"contracts"|`contracts`|contracts)';
        $qualifier = '(?:(?:"[^"]+"|`[^`]+`|[a-z_][a-z0-9_$]*)\s*\.\s*)?';
        $mutation = '/\b(?:insert\s+into|update|delete\s+from|merge\s+into|truncate(?:\s+table)?|copy)\s+(?:only\s+)?'.$qualifier.$identifier.'(?![a-z0-9_$])/is';
        $writableFunction = '/\b(?:(?:mutate|update|delete|insert|create|save|upsert)_?contracts?|contracts?_(?:mutate|update|delete|insert|create|save|upsert))\s*\(/i';

        return preg_match($mutation, $sql) === 1 || preg_match($writableFunction, $sql) === 1;
    }

    private function isDatabaseFacadeName(string $name): bool
    {
        return ltrim($name, '\\') === 'Illuminate\\Support\\Facades\\DB';
    }

    private function rootedAtContractsTable(Node\Expr $expression): bool
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            if ($expression instanceof Node\Expr\MethodCall
                && $expression->name instanceof Node\Identifier
                && $expression->name->toString() === 'table'
                && isset($expression->args[0])
                && $expression->args[0]->value instanceof Node\Scalar\String_
                && strtolower($expression->args[0]->value->value) === 'contracts'
                && $this->rootedAtDatabaseConnection($expression->var)) {
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

        return strtolower($expression->args[0]->value->value) === 'contracts';
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
