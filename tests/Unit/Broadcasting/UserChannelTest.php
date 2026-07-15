<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasting;

require_once dirname(__DIR__, 3).'/app/Broadcasting/UserChannel.php';
require_once dirname(__DIR__, 3).'/app/Broadcasting/OrganizationUserChannel.php';

use App\Broadcasting\OrganizationUserChannel;
use App\Broadcasting\UserChannel;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class UserChannelTest extends TestCase
{
    public function test_admin_endpoint_only_authorizes_the_admin_interface_for_the_same_user(): void
    {
        $channel = new UserChannel(Request::create('/api/v1/admin/broadcasting/auth', 'POST'));

        self::assertTrue($channel->join($this->user(42), 42, 'admin'));
        self::assertFalse($channel->join($this->user(42), 42, 'lk'));
        self::assertFalse($channel->join($this->user(42), 7, 'admin'));
    }

    public function test_landing_endpoint_only_authorizes_the_lk_interface(): void
    {
        $channel = new UserChannel(Request::create('/api/v1/landing/broadcasting/auth', 'POST'));

        self::assertTrue($channel->join($this->user(42), 42, 'lk'));
        self::assertFalse($channel->join($this->user(42), 42, 'admin'));
    }

    public function test_generic_broadcasting_endpoint_is_never_authorized(): void
    {
        $channel = new UserChannel(Request::create('/broadcasting/auth', 'POST'));

        self::assertFalse($channel->join($this->user(42), 42, 'admin'));
        self::assertFalse($channel->join($this->user(42), 42, 'lk'));
    }

    public function test_channel_routes_expose_only_global_and_organization_scoped_names(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/routes/channels.php');

        self::assertIsString($routes);
        self::assertStringContainsString('App.Models.User.{id}.{interface}.global', $routes);
        self::assertStringContainsString('App.Models.User.{id}.{interface}.org.{organizationId}', $routes);
        self::assertStringNotContainsString("'App.Models.User.{id}.{interface}'", $routes);
    }

    public function test_organization_channel_requires_exact_user_interface_and_active_membership(): void
    {
        $baseChannel = new UserChannel(Request::create('/api/v1/admin/broadcasting/auth', 'POST'));
        $channel = new OrganizationUserChannel($baseChannel);
        $user = $this->user(42, [17]);

        self::assertTrue($channel->join($user, 42, 'admin', 17));
        self::assertFalse($channel->join($user, 42, 'admin', 18));
        self::assertFalse($channel->join($user, 7, 'admin', 17));
        self::assertFalse($channel->join($user, 42, 'lk', 17));
    }

    private function user(int $id, array $organizationIds = []): User
    {
        $user = new ChannelTestUser($organizationIds);
        $user->setRawAttributes(['id' => $id], true);

        return $user;
    }
}

final class ChannelTestUser extends User
{
    public function __construct(private readonly array $organizationIds = [])
    {
        parent::__construct();
    }

    public function belongsToOrganization(int $organizationId): bool
    {
        return in_array($organizationId, $this->organizationIds, true);
    }
}
