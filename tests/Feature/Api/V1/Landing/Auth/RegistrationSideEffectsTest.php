<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Jobs\Auth\CompleteRegistrationSideEffects;
use App\Models\AuthRegistrationAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RegistrationSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_dispatches_side_effects_only_after_commit_and_not_on_replay(): void
    {
        Queue::fake();
        $payload = $this->payload();
        $request = fn () => $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.lk.0'),
            'Idempotency-Key' => 'registration-side-effects-key',
        ])->postJson('/api/v1/landing/auth/register', $payload);

        $request()->assertCreated();
        Queue::assertPushed(
            CompleteRegistrationSideEffects::class,
            static fn (CompleteRegistrationSideEffects $job): bool => $job->afterCommit === true,
        );

        Queue::assertPushed(CompleteRegistrationSideEffects::class, 1);

        $request()->assertCreated();
        Queue::assertPushed(CompleteRegistrationSideEffects::class, 1);
    }

    public function test_job_records_completed_steps_and_does_not_repeat_email_on_retry(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create(['tax_number' => null]);
        $user = User::factory()->create([
            'email_verified_at' => null,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);
        AuthRegistrationAttempt::query()->create([
            'audience' => 'lk',
            'idempotency_key' => 'job-idempotency-key',
            'request_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'user_id' => $user->id,
            'response' => [],
            'side_effects' => [],
            'expires_at' => now()->addDay(),
        ]);
        $job = new CompleteRegistrationSideEffects($user->id, $organization->id);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        Notification::assertSentToTimes($user, EmailVerificationNotification::class, 1);
        $state = AuthRegistrationAttempt::query()->where('user_id', $user->id)->value('side_effects');
        $state = is_string($state) ? json_decode($state, true, 16, JSON_THROW_ON_ERROR) : $state;
        self::assertSame('completed', $state['invitations'] ?? null);
        self::assertSame('completed', $state['contractor_sync'] ?? null);
        self::assertSame('completed', $state['email_verification'] ?? null);
    }

    public function test_job_does_not_repeat_a_side_effect_left_executing_by_a_crashed_worker(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create(['tax_number' => null]);
        $user = User::factory()->create([
            'email_verified_at' => null,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);
        AuthRegistrationAttempt::query()->create([
            'audience' => 'lk',
            'idempotency_key' => 'crashed-side-effect-key',
            'request_hash' => str_repeat('b', 64),
            'status' => 'completed',
            'user_id' => $user->id,
            'response' => [],
            'side_effects' => [
                'invitations' => 'completed',
                'contractor_sync' => 'completed',
                'email_verification' => 'executing',
            ],
            'expires_at' => now()->addDay(),
        ]);

        app()->call([new CompleteRegistrationSideEffects($user->id, $organization->id), 'handle']);

        Notification::assertNothingSent();
    }

    /** @return array<string, bool|string> */
    private function payload(): array
    {
        return [
            'name' => 'Side Effects Owner',
            'email' => 'registration-side-effects@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Side Effects Organization',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }
}
