<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class EstimateGenerationStatusMutationBoundaryTest extends TestCase
{
    private const SESSION_CLASS = 'App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationSession';

    /** @var list<string> */
    private const ALLOWED_MUTATORS = [
        'Domain/Workflow/EloquentSessionStateStore.php',
    ];

    private const MANAGED_FIELDS = [
        'status', 'state_version', 'resume_status', 'processing_stage', 'processing_progress',
        'problem_flags', 'draft_payload', 'analysis_payload', 'analysis_result', 'last_error',
        'input_payload', 'applied_estimate_id', 'applied_at', 'generation_attempt_id',
    ];

    #[Test]
    public function all_managed_session_fields_are_mutated_only_by_the_state_store(): void
    {
        self::assertSame([], $this->violations());
    }

    #[Test]
    public function session_factory_delegates_initial_state_to_the_state_store(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)
            .'/app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/CreateEstimateGenerationSession.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('$this->stateStore->create($attributes)', $source);
        self::assertStringNotContainsString("'status' =>", $source);
        self::assertStringNotContainsString('->update(', $source);
        self::assertStringNotContainsString('->upsert(', $source);
        self::assertStringNotContainsString('->forceFill(', $source);
    }

    #[Test]
    public function adversarial_alias_dynamic_and_variable_mutations_are_detected(): void
    {
        $snippets = [
            '<?php use '.self::SESSION_CLASS.' as Run; function x(Run $source): void { $alias = $source; $attrs = ["processing_stage" => "x"]; $alias->update($attrs); }',
            '<?php function x(\\'.self::SESSION_CLASS.' $session): void { $field = "status"; $session->{$field} = "applied"; }',
            '<?php function x(\\'.self::SESSION_CLASS.' $session): void { $session["processing_progress"]++; }',
            '<?php function x(\\'.self::SESSION_CLASS.' $session): void { $session->setAttribute("draft_payload", []); }',
            '<?php function x($document): void { $document->session()->forceFill(["last_error" => "x"]); }',
        ];

        foreach ($snippets as $snippet) {
            $path = tempnam(sys_get_temp_dir(), 'estimate-boundary-');
            self::assertIsString($path);
            file_put_contents($path, $snippet);
            try {
                self::assertNotSame([], $this->fileViolations($path), $snippet);
            } finally {
                unlink($path);
            }
        }
    }

    /** @return list<string> */
    private function violations(): array
    {
        $root = dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'migrations/') || in_array($relative, self::ALLOWED_MUTATORS, true)) {
                continue;
            }

            foreach ($this->fileViolations($file->getPathname()) as $line) {
                $violations[] = $relative.':'.$line;
            }
        }

        sort($violations);

        return $violations;
    }

    /** @return list<int> */
    private function fileViolations(string $path): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $nodes = $parser->parse((string) file_get_contents($path)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);
        $finder = new NodeFinder;
        $sessionVariables = $this->sessionVariables($nodes, $finder);
        $managedArrays = $this->managedArrayVariables($nodes, $finder);
        $lines = [];

        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\PropertyFetch
                && $this->isSessionReceiver($assignment->var->var, $sessionVariables)
                && ($assignment->var->name instanceof Node\Expr || $this->isManagedField($assignment->var->name))) {
                $lines[] = $assignment->getStartLine();
            }
            if ($assignment->var instanceof Node\Expr\ArrayDimFetch
                && $this->isSessionReceiver($assignment->var->var, $sessionVariables)
                && ($assignment->var->dim instanceof Node\Expr || $this->isManagedField($assignment->var->dim))) {
                $lines[] = $assignment->getStartLine();
            }
        }

        foreach ([Node\Expr\AssignOp::class, Node\Expr\PreInc::class, Node\Expr\PostInc::class, Node\Expr\PreDec::class, Node\Expr\PostDec::class] as $mutationClass) {
            foreach ($finder->findInstanceOf($nodes, $mutationClass) as $mutation) {
                $target = $mutation instanceof Node\Expr\AssignOp ? $mutation->var : $mutation->var;
                if ($this->isManagedTarget($target, $sessionVariables)) {
                    $lines[] = $mutation->getStartLine();
                }
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Unset_::class) as $unset) {
            foreach ($unset->vars as $target) {
                if ($this->isManagedTarget($target, $sessionVariables)) {
                    $lines[] = $unset->getStartLine();
                }
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
            if (! $this->isSessionReceiver($call->var, $sessionVariables)
                || ! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), ['fill', 'forceFill', 'update', 'updateOrCreate', 'upsert', 'setAttribute'], true)) {
                continue;
            }

            if ($this->argumentsMutateManagedFields($call->args, $managedArrays)) {
                $lines[] = $call->getStartLine();
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name
                || $call->class->toString() !== self::SESSION_CLASS
                || ! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), ['create', 'forceCreate', 'updateOrCreate', 'upsert'], true)) {
                continue;
            }

            if ($this->argumentsMutateManagedFields($call->args, $managedArrays)) {
                $lines[] = $call->getStartLine();
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return $lines;
    }

    /** @param list<Node> $nodes @return array<string, true> */
    private function managedArrayVariables(array $nodes, NodeFinder $finder): array
    {
        $variables = [];
        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)
                && $assignment->expr instanceof Node\Expr\Array_
                && $this->arrayMutatesManagedFields($assignment->expr)) {
                $variables[$assignment->var->name] = true;
            }
        }

        return $variables;
    }

    /** @param list<Node> $nodes @return array<string, true> */
    private function sessionVariables(array $nodes, NodeFinder $finder): array
    {
        $variables = [];
        foreach ($finder->findInstanceOf($nodes, Node\Param::class) as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)
                && $param->type instanceof Node\Name && $param->type->toString() === self::SESSION_CLASS) {
                $variables[$param->var->name] = true;
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
                if (! $assignment->var instanceof Node\Expr\Variable || ! is_string($assignment->var->name)) {
                    continue;
                }
                if ($this->isSessionReceiver($assignment->expr, $variables) && ! isset($variables[$assignment->var->name])) {
                    $variables[$assignment->var->name] = true;
                    $changed = true;
                }
            }
        }

        return $variables;
    }

    /** @param array<string, true> $variables */
    private function isSessionReceiver(Node\Expr $expr, array $variables): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return isset($variables[$expr->name]);
        }

        if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
            return $expr->class->toString() === self::SESSION_CLASS;
        }

        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\PropertyFetch) {
            if ($expr instanceof Node\Expr\MethodCall
                && $expr->name instanceof Node\Identifier
                && in_array($expr->name->toString(), ['session', 'estimateGenerationSession', 'estimateGenerationSessions'], true)) {
                return true;
            }

            return $this->isSessionReceiver($expr->var, $variables);
        }

        return false;
    }

    /** @param list<Node\Arg> $arguments @param array<string, true> $managedArrays */
    private function argumentsMutateManagedFields(array $arguments, array $managedArrays): bool
    {
        foreach ($arguments as $argument) {
            if ($argument->value instanceof Node\Expr\Variable && is_string($argument->value->name)
                && isset($managedArrays[$argument->value->name])) {
                return true;
            }
            if ($argument->value instanceof Node\Scalar\String_ && in_array($argument->value->value, self::MANAGED_FIELDS, true)) {
                return true;
            }
            if ($argument->value instanceof Node\Expr\Array_ && $this->arrayMutatesManagedFields($argument->value)) {
                return true;
            }
        }

        return false;
    }

    private function arrayMutatesManagedFields(Node\Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item?->key instanceof Node\Scalar\String_ && in_array($item->key->value, self::MANAGED_FIELDS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $variables */
    private function isManagedTarget(Node\Expr $target, array $variables): bool
    {
        return ($target instanceof Node\Expr\PropertyFetch
                && $this->isSessionReceiver($target->var, $variables)
                && ($target->name instanceof Node\Expr || $this->isManagedField($target->name)))
            || ($target instanceof Node\Expr\ArrayDimFetch
                && $this->isSessionReceiver($target->var, $variables)
                && $this->isManagedField($target->dim));
    }

    private function isManagedField(Node\Identifier|Node\Expr $name): bool
    {
        return ($name instanceof Node\Identifier && in_array($name->toString(), self::MANAGED_FIELDS, true))
            || ($name instanceof Node\Scalar\String_ && in_array($name->value, self::MANAGED_FIELDS, true));
    }
}
