<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityResolver;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class AssistantReportEntityResolverTest extends TestCase
{
    public function test_resolves_single_exact_project_match(): void
    {
        $resolver = $this->resolver(new EntityResolverFakeTool('search_projects', [
            ['id' => 12, 'name' => 'Склад Литер А'],
            ['id' => 13, 'name' => 'Склад Литер Б'],
        ]));

        $result = $resolver->resolve('project', 'Склад Литер А', new User, new Organization);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame(['id' => 12, 'label' => 'Склад Литер А'], $result['entity']);
    }

    public function test_single_partial_match_is_accepted(): void
    {
        $resolver = $this->resolver(new EntityResolverFakeTool('search_warehouse', [
            ['id' => 7, 'name' => 'Основной склад'],
        ]));

        $result = $resolver->resolve('warehouse', 'основной', new User, new Organization);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame(['id' => 7, 'label' => 'Основной склад'], $result['entity']);
    }

    public function test_multiple_matches_require_clarification(): void
    {
        $resolver = $this->resolver(new EntityResolverFakeTool('search_contractors', [
            ['id' => 4, 'name' => 'Монолит'],
            ['id' => 5, 'name' => 'Монолит Север'],
        ]));

        $result = $resolver->resolve('contractor', 'Монолит', new User, new Organization);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame(['id' => 4, 'label' => 'Монолит'], $result['entity']);

        $result = $resolver->resolve('contractor', 'Мон', new User, new Organization);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertCount(2, $result['candidates']);
    }

    public function test_empty_results_are_not_found(): void
    {
        $resolver = $this->resolver(new EntityResolverFakeTool('search_users', []));

        $result = $resolver->resolve('user', 'Иванов', new User, new Organization);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], $result['candidates']);
    }

    public function test_unsupported_entity_type_is_safe(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve('material', 'цемент', new User, new Organization);

        $this->assertSame('unsupported', $result['status']);
    }

    private function resolver(?AIToolInterface $tool = null): AssistantReportEntityResolver
    {
        $registry = new AIToolRegistry;
        if ($tool instanceof AIToolInterface) {
            $registry->registerTool($tool);
        }

        return new AssistantReportEntityResolver($registry);
    }
}

final class EntityResolverFakeTool implements AIToolInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    public function __construct(
        private readonly string $name,
        private readonly array $results
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'fake';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        unset($arguments, $user, $organization);

        return [
            'status' => 'success',
            'results' => $this->results,
        ];
    }
}
