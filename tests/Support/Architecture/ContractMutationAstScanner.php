<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class ContractMutationAstScanner
{
    private const MUTATIONS = ['save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'forceDelete', 'insert'];

    /** @return list<int> */
    public function scan(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $printer = new Standard;
        $contractVariables = [];

        foreach ($finder->findInstanceOf($nodes, Node\Param::class) as $parameter) {
            if ($parameter->var instanceof Node\Expr\Variable && $this->nameEndsWith($parameter->type, 'Contract')) {
                $contractVariables[(string) $parameter->var->name] = true;
            }
        }
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable && $this->expressionContainsContractQuery($assignment->expr, $printer)) {
                $contractVariables[(string) $assignment->var->name] = true;
            }
        }

        $violations = [];
        foreach ($finder->find($nodes, function (Node $node) use ($contractVariables, $printer): bool {
            if ($node instanceof Node\Expr\StaticCall) {
                $class = $node->class instanceof Node\Name ? $node->class->toString() : $printer->prettyPrintExpr($node->class);
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
                if (str_ends_with($class, 'Contract') && in_array($method, ['create', 'updateOrCreate', 'firstOrCreate', 'destroy'], true)) {
                    return true;
                }
                if ($class === 'DB' && in_array($method, ['statement', 'unprepared', 'insert', 'update', 'delete'], true)) {
                    $sql = $node->args[0]->value ?? null;

                    return $sql instanceof Node\Scalar\String_ && preg_match('/\b(insert\s+into|update|delete\s+from)\s+["`]?contracts\b/i', $sql->value) === 1;
                }
            }
            if (! $node instanceof Node\Expr\MethodCall || ! $node->name instanceof Node\Identifier) {
                return false;
            }
            $method = $node->name->toString();
            if (! in_array($method, self::MUTATIONS, true)) {
                return false;
            }
            $receiver = $printer->prettyPrintExpr($node->var);
            $rootVariable = $this->rootVariable($node->var);
            if ($rootVariable !== null && isset($contractVariables[$rootVariable])) {
                return true;
            }

            return str_contains($receiver, 'Contract::query()')
                || str_contains($receiver, "DB::table('contracts')")
                || str_contains($receiver, "DB::connection()->table('contracts')")
                || str_contains($receiver, 'contractRepository');
        }) as $node) {
            $violations[] = $node->getStartLine();
        }

        return array_values(array_unique($violations));
    }

    private function expressionContainsContractQuery(Node\Expr $expression, Standard $printer): bool
    {
        $printed = $printer->prettyPrintExpr($expression);

        return preg_match('/^(?:\\\\?App\\\\Models\\\\)?Contract::/', $printed) === 1
            || preg_match('/^(?:\$this->)?contractRepository->(?:find|findOrFail|getById)/', $printed) === 1
            || str_contains($printed, "DB::table('contracts')")
            || str_contains($printed, "DB::connection()->table('contracts')");
    }

    private function rootVariable(Node\Expr $expression): ?string
    {
        while ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\PropertyFetch) {
            $expression = $expression->var;
        }

        return $expression instanceof Node\Expr\Variable && is_string($expression->name) ? $expression->name : null;
    }

    private function nameEndsWith(?Node $node, string $suffix): bool
    {
        return $node instanceof Node\Name && str_ends_with($node->toString(), $suffix);
    }
}
