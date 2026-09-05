<?php

declare(strict_types=1);

namespace Tests\Unit\KnowledgeHub;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeAudience;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeAccessContextFactory;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class KnowledgeAccessContextFactoryTest extends TestCase
{
    public function test_client_cannot_select_a_more_privileged_surface(): void
    {
        foreach ([KnowledgeSurface::LK, KnowledgeSurface::ADMIN, KnowledgeSurface::MOBILE] as $surface) {
            $request = Request::create('/knowledge-hub', 'GET', ['surface' => 'superadmin']);
            $request->setUserResolver(static fn () => null);

            $context = (new KnowledgeAccessContextFactory())->fromRequest($request, $surface);

            self::assertSame($surface, $context->surface);
            self::assertNotContains(KnowledgeAudience::SYSTEM_ADMIN->value, $context->audiences);
        }
    }

    public function test_server_can_select_the_system_admin_surface(): void
    {
        $request = Request::create('/knowledge-hub', 'GET', ['surface' => 'lk']);
        $request->setUserResolver(static fn () => null);

        $context = (new KnowledgeAccessContextFactory())->fromRequest($request, KnowledgeSurface::SUPERADMIN);

        self::assertSame(KnowledgeSurface::SUPERADMIN, $context->surface);
        self::assertContains(KnowledgeAudience::SYSTEM_ADMIN->value, $context->audiences);
    }
}
