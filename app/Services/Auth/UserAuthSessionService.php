<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Enums\AuthSecurityEventType;
use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Models\UserSecurityEvent;
use App\Services\Activity\ActivityEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UserAuthSessionService
{
    public function __construct(
        private readonly DeviceFingerprintService $fingerprints,
        private readonly AuthRiskService $riskService,
        private readonly ActivityEventRecorder $activityRecorder,
        private readonly NotificationService $notificationService,
    ) {}

    public function createForLogin(User $user, ?int $organizationId, Request $request): UserAuthSession
    {
        return DB::transaction(function () use ($user, $organizationId, $request): UserAuthSession {
            $fingerprint = $this->fingerprints->fingerprint($request);
            $risk = $this->riskService->score($user, $request, $fingerprint);
            $isNewDevice = ! UserAuthSession::query()
                ->where('user_id', $user->id)
                ->where('device_fingerprint', $fingerprint)
                ->exists();

            $session = UserAuthSession::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'session_uuid' => (string) Str::uuid(),
                'device_fingerprint' => $fingerprint,
                'device_name' => $this->fingerprints->deviceName($request),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'risk_score' => $risk['score'],
                'risk_flags' => $risk['flags'],
                'status' => AuthSessionStatus::Active,
                'is_trusted' => false,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            $this->createEvent(
                $user,
                $session,
                $isNewDevice ? AuthSecurityEventType::NewDeviceLogin : AuthSecurityEventType::LoginSuccess,
                $request,
                $risk['score'],
                $risk['flags'],
                ['device_name' => $session->device_name]
            );

            $this->enforceDeviceLimit($user, $session, $request);
            $this->notifyNewDevice($user, $session, $isNewDevice, $request);

            return $session;
        });
    }

    public function findActiveByUuid(?string $sessionUuid): ?UserAuthSession
    {
        if (! $sessionUuid) {
            return null;
        }

        return UserAuthSession::query()
            ->active()
            ->where('session_uuid', $sessionUuid)
            ->first();
    }

    public function touch(UserAuthSession $session): void
    {
        $seconds = max(60, (int) config('auth_tokens.sessions.last_seen_update_seconds', 300));
        $cacheKey = "auth_session_touch:{$session->id}";

        if (Cache::add($cacheKey, true, $seconds)) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function revoke(UserAuthSession $session, string $reason): void
    {
        $session->forceFill([
            'status' => AuthSessionStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ])->save();

        $this->createEvent(
            $session->user,
            $session,
            AuthSecurityEventType::SessionRevoked,
            request(),
            (int) $session->risk_score,
            $session->risk_flags ?? [],
            ['reason' => $reason]
        );
    }

    public function revokeOtherSessions(User $user, string $currentSessionUuid, string $reason): int
    {
        $sessions = UserAuthSession::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('session_uuid', '!=', $currentSessionUuid)
            ->get();

        foreach ($sessions as $session) {
            $this->revoke($session, $reason);
        }

        UserSecurityEvent::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'type' => AuthSecurityEventType::OtherSessionsRevoked,
            'risk_score' => 0,
            'risk_flags' => [],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => ['revoked_count' => $sessions->count()],
        ]);

        return $sessions->count();
    }

    public function createEvent(
        User $user,
        ?UserAuthSession $session,
        AuthSecurityEventType $type,
        Request $request,
        int $riskScore,
        array $riskFlags,
        array $metadata = []
    ): UserSecurityEvent {
        return UserSecurityEvent::query()->create([
            'user_id' => $user->id,
            'organization_id' => $session?->organization_id ?? $user->current_organization_id,
            'auth_session_id' => $session?->id,
            'type' => $type,
            'risk_score' => $riskScore,
            'risk_flags' => $riskFlags,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata,
        ]);
    }

    private function enforceDeviceLimit(User $user, UserAuthSession $currentSession, Request $request): void
    {
        $limit = max(1, (int) config('auth_tokens.sessions.max_active_per_user', 3));
        $activeSessions = UserAuthSession::query()
            ->active()
            ->where('user_id', $user->id)
            ->orderBy('last_seen_at')
            ->get();

        if ($activeSessions->count() <= $limit) {
            return;
        }

        $sessionsToRevoke = $activeSessions
            ->where('id', '!=', $currentSession->id)
            ->take($activeSessions->count() - $limit);

        foreach ($sessionsToRevoke as $session) {
            $this->revoke($session, 'device_limit_exceeded');
        }

        $this->createEvent(
            $user,
            $currentSession,
            AuthSecurityEventType::DeviceLimitReached,
            $request,
            (int) $currentSession->risk_score,
            $currentSession->risk_flags ?? [],
            ['limit' => $limit, 'revoked_count' => $sessionsToRevoke->count()]
        );
    }

    private function notifyNewDevice(User $user, UserAuthSession $session, bool $isNewDevice, Request $request): void
    {
        if (! $isNewDevice || ! (bool) config('auth_tokens.sessions.notify_new_device', true)) {
            return;
        }

        if (! class_exists(\App\Notifications\NewDeviceLoginNotification::class)) {
            return;
        }

        try {
            $user->notify(new \App\Notifications\NewDeviceLoginNotification($session));
        } catch (\Throwable $e) {
            Log::warning('Failed to send new device notification', [
                'user_id' => $user->id,
                'auth_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->sendNewDeviceInAppNotification($user, $session);
        $this->recordNewDeviceActivity($user, $session, $request);
    }

    private function sendNewDeviceInAppNotification(User $user, UserAuthSession $session): void
    {
        try {
            $this->notificationService->send(
                $user,
                'auth.new_device_login',
                [
                    'title' => trans_message('auth.security_new_device_title'),
                    'message' => trans_message('auth.security_new_device_message', [
                        'device' => $session->device_name ?: trans_message('auth.security_unknown_device'),
                        'ip' => $session->ip_address ?: trans_message('auth.security_unknown_ip'),
                    ]),
                    'category' => 'security',
                    'notification_type' => 'security',
                    'priority' => 'high',
                    'interface' => 'admin',
                    'entity_type' => 'auth_session',
                    'entity_id' => $session->id,
                    'target_route' => '/profile-settings',
                    'organization_id' => $session->organization_id,
                    'context' => [
                        'device_name' => $session->device_name,
                        'ip_address' => $session->ip_address,
                        'risk_score' => $session->risk_score,
                        'risk_flags' => $session->risk_flags ?? [],
                    ],
                    'force_send' => true,
                ],
                'security',
                'high',
                ['in_app', 'websocket'],
                $session->organization_id,
                interfaces: ['admin'],
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to create new device in-app notification', [
                'user_id' => $user->id,
                'auth_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordNewDeviceActivity(User $user, UserAuthSession $session, Request $request): void
    {
        $organizationId = $session->organization_id ?? $user->current_organization_id;

        if (! $organizationId) {
            return;
        }

        $this->activityRecorder->record(ActivityEventData::make(
            organizationId: (int) $organizationId,
            module: 'security',
            eventType: 'auth.new_device_login',
            action: ActivityActionEnum::Login,
            actorUserId: $user->id,
            actorName: $user->name,
            actorEmail: $user->email,
            interface: 'admin',
            result: ActivityResultEnum::Warning,
            severity: ActivitySeverityEnum::Warning,
            subjectType: UserAuthSession::class,
            subjectId: $session->id,
            subjectLabel: $session->device_name,
            targetUserId: $user->id,
            title: trans_message('auth.security_new_device_activity_title'),
            description: trans_message('auth.security_new_device_activity_description', [
                'device' => $session->device_name ?: trans_message('auth.security_unknown_device'),
                'ip' => $session->ip_address ?: trans_message('auth.security_unknown_ip'),
            ]),
            context: [
                'device_name' => $session->device_name,
                'ip_address' => $session->ip_address,
                'risk_score' => $session->risk_score,
                'risk_flags' => $session->risk_flags ?? [],
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }
}
