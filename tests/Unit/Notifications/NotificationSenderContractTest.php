<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class NotificationSenderContractTest extends TestCase
{
    private const EXPECTED_CALLS_BY_FILE = [
        'app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationNotificationService.php' => 1,
        'app/BusinessModules/Core/Payments/Services/PaymentValidationService.php' => 1,
        'app/BusinessModules/Features/Notifications/Integration/ContractEventIntegration.php' => 2,
        'app/BusinessModules/Features/Procurement/Listeners/SendProcurementNotifications.php' => 5,
        'app/BusinessModules/Features/SiteRequests/Services/SiteRequestNotificationService.php' => 1,
        'app/Services/Auth/UserAuthSessionService.php' => 1,
        'app/Services/Filament/NotificationTemplateManagementService.php' => 1,
        'app/Services/OneCExchange/OneCExchangeIncidentNotificationService.php' => 1,
        'app/Services/OneCExchange/OneCExchangeIncidentService.php' => 1,
        'app/Services/Security/ContractorRegistrationNotificationService.php' => 2,
    ];

    private const VALID_INTERFACES = ['admin', 'lk', 'mobile', 'customer'];

    private const NOTIFICATION_SERVICE = 'App\\BusinessModules\\Features\\Notifications\\Services\\NotificationService';

    private const NOTIFY_FACADE = 'App\\BusinessModules\\Features\\Notifications\\Facades\\Notify';

    public function test_every_known_business_sender_declares_a_valid_explicit_interface(): void
    {
        $calls = $this->notificationCalls();

        self::assertCount(16, $calls, 'The maintained sender inventory must stay non-empty and complete.');

        foreach ($calls as $call) {
            $interfaces = $this->namedStringValues($call['node'], 'interfaces');

            self::assertNotNull($interfaces, "{$call['file']}:{$call['line']} omits named interfaces");
            self::assertNotSame([], $interfaces, "{$call['file']}:{$call['line']} declares no interfaces");
            self::assertSame(
                [],
                array_values(array_diff($interfaces, self::VALID_INTERFACES)),
                "{$call['file']}:{$call['line']} declares an invalid interface",
            );
        }
    }

    public function test_sensitive_senders_declare_their_domain_permissions(): void
    {
        $expectedByFile = [
            'app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationNotificationService.php' => ['budget-estimates.view'],
            'app/BusinessModules/Features/SiteRequests/Services/SiteRequestNotificationService.php' => ['notifications.receive.site_requests'],
            'app/Services/OneCExchange/OneCExchangeIncidentNotificationService.php' => ['one_c_exchange.view'],
            'app/Services/OneCExchange/OneCExchangeIncidentService.php' => ['one_c_exchange.view'],
        ];
        $expected = [
            'contract_status_changed' => ['contracts.view'],
            'contract_limit_warning' => ['contracts.view'],
            'payment.contract_excess' => ['payments.invoice.view', 'payments.invoice.view_all'],
            'procurement.purchase_request.created' => ['notifications.receive.procurement'],
            'procurement.purchase_request.approved' => ['notifications.receive.procurement'],
            'procurement.purchase_order.sent' => ['notifications.receive.procurement'],
            'procurement.materials.received' => ['notifications.receive.procurement'],
        ];
        $matched = [];

        foreach ($this->notificationCalls() as $call) {
            if (array_key_exists($call['file'], $expectedByFile)) {
                self::assertSame(
                    $expectedByFile[$call['file']],
                    $this->namedStringValues($call['node'], 'requiredPermissions'),
                    "{$call['file']}:{$call['line']} has wrong permissions",
                );
            }

            $type = $this->positionalStringValue($call['node'], 1);

            if ($type === null || ! array_key_exists($type, $expected)) {
                continue;
            }

            $permissions = $this->namedStringValues($call['node'], 'requiredPermissions');
            self::assertSame($expected[$type], $permissions, "{$call['file']}:{$call['line']} has wrong permissions");
            $matched[$type] = ($matched[$type] ?? 0) + 1;
        }

        $expectedCounts = [
            'contract_status_changed' => 1,
            'contract_limit_warning' => 1,
            'payment.contract_excess' => 1,
            'procurement.purchase_request.created' => 1,
            'procurement.purchase_request.approved' => 1,
            'procurement.purchase_order.sent' => 1,
            'procurement.materials.received' => 2,
        ];
        ksort($expectedCounts);
        ksort($matched);

        self::assertSame($expectedCounts, $matched);
    }

    public function test_wrapper_and_template_contracts_are_explicit_and_customer_safe(): void
    {
        $serviceSource = (string) file_get_contents($this->projectPath(
            'app/BusinessModules/Features/Notifications/Services/NotificationService.php'
        ));
        $templateSource = (string) file_get_contents($this->projectPath(
            'app/Services/Filament/NotificationTemplateManagementService.php'
        ));

        self::assertStringContainsString('interfaces: $options[\'interfaces\']', $serviceSource);
        self::assertStringNotContainsString('interfaces: $options[\'interfaces\'] ?? null', $serviceSource);
        self::assertStringContainsString("|| \$options['interfaces'] === null", $serviceSource);
        self::assertStringContainsString("|| \$options['interfaces'] === []", $serviceSource);
        self::assertStringContainsString("interfaces: ['customer']", $templateSource);
        self::assertStringContainsString('requiredPermissions: []', $templateSource);
        self::assertStringContainsString('assertCustomerChannelSupported', $templateSource);
    }

    /**
     * @return list<array{file: string, line: int, node: Node\Expr\MethodCall|Node\Expr\StaticCall}>
     */
    private function notificationCalls(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $calls = [];
        $actualCallsByFile = [];

        foreach ($this->applicationPhpFiles() as $relativePath) {
            $source = (string) file_get_contents($this->projectPath($relativePath));
            $nodes = $parser->parse($source);
            self::assertNotNull($nodes, "Unable to parse {$relativePath}");

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $nodes = $traverser->traverse($nodes);
            [$serviceVariables, $serviceProperties] = $this->notificationServiceSymbols($nodes, $finder);

            $fileCalls = array_values(array_filter(
                $finder->findInstanceOf($nodes, Node\Expr\CallLike::class),
                fn (Node $node): bool => $this->isNotificationSendCall(
                    $node,
                    $serviceVariables,
                    $serviceProperties,
                ),
            ));

            if ($fileCalls !== []) {
                $actualCallsByFile[$relativePath] = count($fileCalls);
            }

            foreach ($fileCalls as $node) {
                self::assertInstanceOf(Node\Expr\CallLike::class, $node);
                $calls[] = [
                    'file' => $relativePath,
                    'line' => $node->getStartLine(),
                    'node' => $node,
                ];
            }
        }

        $expectedCallsByFile = self::EXPECTED_CALLS_BY_FILE;
        ksort($expectedCallsByFile);
        ksort($actualCallsByFile);

        self::assertSame(
            $expectedCallsByFile,
            $actualCallsByFile,
            'Discovered NotificationService/Notify send calls differ from the maintained sender manifest.',
        );

        return $calls;
    }

    /**
     * @param  list<Node>  $nodes
     * @return array{0: list<string>, 1: list<string>}
     */
    private function notificationServiceSymbols(array $nodes, NodeFinder $finder): array
    {
        $variables = [];
        $properties = [];

        foreach ($finder->findInstanceOf($nodes, Node\Param::class) as $parameter) {
            if (! $this->isNotificationServiceType($parameter->type)
                || ! $parameter->var instanceof Node\Expr\Variable
                || ! is_string($parameter->var->name)) {
                continue;
            }

            $variables[] = $parameter->var->name;

            if ($parameter->flags !== 0) {
                $properties[] = $parameter->var->name;
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Property::class) as $property) {
            if (! $this->isNotificationServiceType($property->type)) {
                continue;
            }

            foreach ($property->props as $propertyItem) {
                $properties[] = $propertyItem->name->toString();
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\Assign::class) as $assignment) {
            if (! $assignment->var instanceof Node\Expr\Variable
                || ! is_string($assignment->var->name)
                || ! $this->resolvesNotificationService($assignment->expr)) {
                continue;
            }

            $variables[] = $assignment->var->name;
        }

        return [array_values(array_unique($variables)), array_values(array_unique($properties))];
    }

    /**
     * @param  list<string>  $serviceVariables
     * @param  list<string>  $serviceProperties
     */
    private function isNotificationSendCall(
        Node $node,
        array $serviceVariables,
        array $serviceProperties,
    ): bool {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'send'
                && $node->class instanceof Node\Name
                && $node->class->toString() === self::NOTIFY_FACADE;
        }

        if (! $node instanceof Node\Expr\MethodCall
            || ! $node->name instanceof Node\Identifier
            || $node->name->toString() !== 'send') {
            return false;
        }

        if ($node->var instanceof Node\Expr\Variable) {
            return is_string($node->var->name) && in_array($node->var->name, $serviceVariables, true);
        }

        return $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->var instanceof Node\Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Node\Identifier
            && in_array($node->var->name->toString(), $serviceProperties, true);
    }

    private function resolvesNotificationService(Node\Expr $expression): bool
    {
        if (! $expression instanceof Node\Expr\FuncCall
            || ! $expression->name instanceof Node\Name
            || $expression->name->toString() !== 'app') {
            return false;
        }

        $argument = $expression->getArgs()[0]->value ?? null;

        return $argument instanceof Node\Expr\ClassConstFetch
            && $argument->name instanceof Node\Identifier
            && $argument->name->toString() === 'class'
            && $argument->class instanceof Node\Name
            && $argument->class->toString() === self::NOTIFICATION_SERVICE;
    }

    private function isNotificationServiceType(?Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return $this->isNotificationServiceType($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $innerType) {
                if ($this->isNotificationServiceType($innerType)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof Node\Name && $type->toString() === self::NOTIFICATION_SERVICE;
    }

    /**
     * @return list<string>
     */
    private function applicationPhpFiles(): array
    {
        $files = [];

        foreach (['app/BusinessModules', 'app/Services'] as $relativeDirectory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $this->projectPath($relativeDirectory),
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $files[] = str_replace('\\', '/', substr(
                    $file->getPathname(),
                    strlen($this->projectPath('')),
                ));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>|null
     */
    private function namedStringValues(Node\Expr\CallLike $call, string $name): ?array
    {
        foreach ($call->getArgs() as $argument) {
            if ($argument->name?->toString() !== $name) {
                continue;
            }

            if ($argument->value instanceof Node\Scalar\String_) {
                return [$argument->value->value];
            }

            if (! $argument->value instanceof Node\Expr\Array_) {
                return null;
            }

            $values = [];
            foreach ($argument->value->items as $item) {
                if (! $item?->value instanceof Node\Scalar\String_) {
                    return null;
                }

                $values[] = $item->value->value;
            }

            return $values;
        }

        return null;
    }

    private function positionalStringValue(Node\Expr\CallLike $call, int $position): ?string
    {
        $argument = $call->getArgs()[$position] ?? null;

        return $argument?->name === null && $argument?->value instanceof Node\Scalar\String_
            ? $argument->value->value
            : null;
    }

    private function projectPath(string $relativePath): string
    {
        return dirname(__DIR__, 3).'/'.$relativePath;
    }
}
