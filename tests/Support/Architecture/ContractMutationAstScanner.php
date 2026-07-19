<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class ContractMutationAstScanner
{
    private const MUTATIONS = ['save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'forceDelete', 'insert', 'upsert', 'increment', 'decrement', 'touch', 'restore', 'create'];

    /** @return list<int> */
    public function scan(string $source): array
    {
        return array_values(array_unique(array_column($this->findings($source), 'line')));
    }

    /** @return list<array{line:int,class:string,method:string,operation:string,receiver:string,fingerprint:string}> */
    public function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
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
        $assignments = $finder->findInstanceOf($scope->getStmts() ?? [], Node\Expr\Assign::class);
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
            }
        } while ($changed);

        $results = [];
        foreach ($finder->find($scope->getStmts() ?? [], fn (Node $node): bool => $this->isMutation($node, $contractVariables, $repositoryVariables, $repositoryProperties, $printer)) as $node) {
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : 'raw_sql';
            $receiver = $node instanceof Node\Expr\MethodCall ? $printer->prettyPrintExpr($node->var) : $printer->prettyPrintExpr($node);
            $fingerprint = implode('|', [$class, $method, $operation, preg_replace('/\s+/', '', $receiver)]);
            $results[] = ['line' => $node->getStartLine(), 'class' => $class, 'method' => $method, 'operation' => $operation, 'receiver' => $receiver, 'fingerprint' => $fingerprint];
        }

        return $results;
    }

    /** @param array<string, true> $variables */
    private function isMutation(Node $node, array $variables, array $repositoryVariables, array $repositoryProperties, Standard $printer): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : $printer->prettyPrintExpr($node->class);
            $operation = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
            if ($this->shortName($class) === 'Contract' && in_array($operation, ['create', 'updateOrCreate', 'firstOrCreate', 'destroy', 'forceDestroy'], true)) {
                return true;
            }
            if ($this->shortName($class) === 'DB' && in_array($operation, ['statement', 'unprepared', 'insert', 'update', 'delete'], true)) {
                $sql = $node->args[0]->value ?? null;

                return $sql instanceof Node\Scalar\String_ && preg_match('/\b(insert\s+into|update|delete\s+from)\s+["`]?contracts\b/i', $sql->value) === 1;
            }
        }
        if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier || ! in_array($node->name->toString(), self::MUTATIONS, true)) {
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
            || str_contains($receiver, 'Contract::query()')
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

        return preg_match('/^(?:\\\\?App\\\\Models\\\\)?Contract::/', $printed) === 1
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

    private function isExactContractType(?Node $node): bool
    {
        return $node instanceof Node\Name && $this->shortName($node->toString()) === 'Contract';
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
