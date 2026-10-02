<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\Models\CustomerRequest;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssistantOfflineAclFixture;

final class AssistantPortalCommentScopeTest extends TestCase
{
    public function test_authored_comment_remains_native_parent_scoped_and_does_not_require_all_organization_projects(): void
    {
        $records = Metadata::records();
        $expected = array_fill_keys(array_keys(array_filter($records, static fn (array $record): bool => $record['project_column'] === null && $record['parents'] === [] && ! $record['global'])), true);
        unset($expected['core_customer_portal_comment']);
        self::assertSame($expected, Metadata::organizationAggregates());
        self::assertSame('author_user_id', $records['core_customer_portal_comment']['actor_column']);
        self::assertSame('organization_id', $records['core_customer_portal_comment']['organization_column']);
        self::assertSame(['projects.view'], $records['core_customer_portal_comment']['permissions']);
        $previousApp = Facade::getFacadeApplication();
        $previousContainer = Container::getInstance();
        $previousResolver = Model::getConnectionResolver();
        try {
            Facade::clearResolvedInstances();
            [$policy, $actor] = AssistantOfflineAclFixture::create();
            $query = $policy->entityQuery($actor, 1, 'core_customer_portal_comment');
            self::assertNotNull($query);
            $query->orderBy('customer_portal_comments.id')->limit(1);
            $sql = $query->toSql();
            self::assertStringContainsString('customer_portal_comments', $sql);
            self::assertStringContainsString('"customer_portal_comments"."author_user_id" = ?', $sql);
            self::assertStringContainsString('"customer_portal_comments"."organization_id" = ?', $sql);
            self::assertStringContainsString('"customer_portal_comments"."commentable_type" in', $sql);
            self::assertStringContainsString('"customer_portal_comments"."commentable_id" in', $sql);
            self::assertStringContainsString('customer_requests', $sql);
            self::assertStringContainsString('"customer_requests"."project_id"', $sql);
            self::assertContains(CustomerRequest::class, $query->getBindings());
            self::assertStringEndsWith('order by "customer_portal_comments"."id" asc limit 1', $sql);
            self::assertNull($policy->entityQuery($actor, 2, 'core_customer_portal_comment'));
        } finally {
            Mockery::close();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousApp);
            Container::setInstance($previousContainer);
            $previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($previousResolver);
        }
    }
}
