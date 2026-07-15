<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Services\UserInvitationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

use function trans_message;

class UserInvitationEmailTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createInvitationTestSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        parent::tearDown();
    }

    public function test_user_invitation_sends_email(): void
    {
        Mail::fake();
        config()->set('app.frontend_url', 'https://lk.test');

        $organization = Organization::query()->create([
            'name' => 'Test Organization',
            'tax_number' => '7700000000',
            'country' => 'RU',
            'is_active' => true,
        ]);
        $invitedBy = User::query()->create([
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => 'password',
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($invitedBy->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        $this->mock(LoggingService::class, function ($mock): void {
            $mock->shouldReceive('business')->zeroOrMoreTimes();
            $mock->shouldReceive('security')->zeroOrMoreTimes();
            $mock->shouldReceive('technical')->zeroOrMoreTimes();
            $mock->shouldReceive('audit')->zeroOrMoreTimes();
        });

        $invitation = app(UserInvitationService::class)->createInvitation([
            'email' => 'new-user@example.com',
            'name' => 'New User',
            'role_slugs' => ['organization_admin'],
        ], $organization->id, $invitedBy);

        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail) use ($invitation): bool {
            $mail->assertHasSubject(trans_message('user_invitations.email.subject'));
            $mail->assertSeeInHtml(trans_message('user_invitations.email.accept_button'));

            return $mail->invitation?->is($invitation)
                && $mail->hasTo('new-user@example.com')
                && $mail->acceptUrl === 'https://lk.test/invitations/accept?token='.urlencode($invitation->token);
        });

        $this->assertNotNull($invitation->fresh()->sent_at);
    }

    private function createInvitationTestSchema(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('tax_number')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('invited_by_user_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->string('email');
            $table->string('name');
            $table->json('role_slugs');
            $table->string('token', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('plain_password')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
