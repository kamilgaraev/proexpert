<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

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

        foreach (self::EXPECTED_CALLS_BY_FILE as $relativePath => $expectedCount) {
            $source = (string) file_get_contents($this->projectPath($relativePath));
            $nodes = $parser->parse($source);
            self::assertNotNull($nodes, "Unable to parse {$relativePath}");

            $fileCalls = array_values(array_filter(
                $finder->findInstanceOf($nodes, Node\Expr\CallLike::class),
                fn (Node $node): bool => $this->isNotificationSendCall($node),
            ));

            self::assertCount($expectedCount, $fileCalls, "Sender inventory drift in {$relativePath}");

            foreach ($fileCalls as $node) {
                self::assertInstanceOf(Node\Expr\CallLike::class, $node);
                $calls[] = [
                    'file' => $relativePath,
                    'line' => $node->getStartLine(),
                    'node' => $node,
                ];
            }
        }

        return $calls;
    }

    private function isNotificationSendCall(Node $node): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->name instanceof Node\Identifier
                && $node->name->toString() === 'send'
                && $node->class instanceof Node\Name
                && $node->class->getLast() === 'Notify';
        }

        if (! $node instanceof Node\Expr\MethodCall
            || ! $node->name instanceof Node\Identifier
            || $node->name->toString() !== 'send') {
            return false;
        }

        if ($node->var instanceof Node\Expr\Variable) {
            return $node->var->name === 'notificationService';
        }

        return $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->var instanceof Node\Expr\Variable
            && $node->var->var->name === 'this'
            && $node->var->name instanceof Node\Identifier
            && in_array($node->var->name->toString(), ['notificationService', 'notifications'], true);
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
