<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\UserAuthSessionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecuritySessionPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_bounded_stable_and_scoped_to_the_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ids = [];
        $time = now();

        for ($index = 0; $index < 55; $index++) {
            $session = UserAuthSession::query()->create([
                'user_id' => $user->id,
                'session_uuid' => (string) Str::uuid(),
                'device_fingerprint' => hash('sha256', (string) $index),
                'status' => AuthSessionStatus::Active,
                'first_seen_at' => $time,
                'last_seen_at' => $time,
            ]);
            $ids[] = $session->id;
        }

        UserAuthSession::query()->create([
            'user_id' => $other->id,
            'session_uuid' => (string) Str::uuid(),
            'device_fingerprint' => hash('sha256', 'other'),
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => $time,
            'last_seen_at' => $time,
        ]);

        $query = app(UserAuthSessionQuery::class);
        $first = $query->paginate($user, 'active', 100, 1);
        $second = $query->paginate($user, 'active', 50, 2);

        $this->assertSame(55, $first->total());
        $this->assertCount(50, $first->items());
        $this->assertCount(5, $second->items());
        $this->assertSame(array_reverse($ids), array_map(
            fn (UserAuthSession $session) => $session->id,
            [...$first->items(), ...$second->items()],
        ));
    }

    public function test_history_includes_expired_and_revoked_sessions_only(): void
    {
        $user = User::factory()->create();
        foreach (AuthSessionStatus::cases() as $status) {
            UserAuthSession::query()->create([
                'user_id' => $user->id,
                'session_uuid' => (string) Str::uuid(),
                'device_fingerprint' => hash('sha256', $status->value),
                'status' => $status,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $query = app(UserAuthSessionQuery::class);
        $this->assertSame(1, $query->paginate($user, 'active')->total());
        $history = $query->paginate($user, 'history');
        $this->assertSame(2, $history->total());
        foreach ($history->items() as $session) {
            $this->assertNotSame(AuthSessionStatus::Active, $session->status);
        }
    }
}
