<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasting;

require_once dirname(__DIR__, 3).'/app/Broadcasting/UserChannel.php';

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

    private function user(int $id): User
    {
        $user = new User;
        $user->setRawAttributes(['id' => $id], true);

        return $user;
    }
}
