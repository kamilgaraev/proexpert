<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Services;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeSpecialization;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BrigadeWorkflowService
{
    public function __construct(private readonly JwtTokenIssuer $tokenIssuer)
    {
    }

    public function register(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $user = new User([
                'name' => $payload['contact_person'],
                'email' => $payload['contact_email'],
                'phone' => $payload['contact_phone'],
                'password' => Hash::make($payload['password']),
                'is_active' => true,
                'has_completed_onboarding' => false,
            ]);
            $user->email_verified_at = now();
            $user->save();

            $brigade = BrigadeProfile::create([
                'owner_user_id' => $user->id,
                'name' => $payload['name'],
                'slug' => $this->makeUniqueSlug($payload['name']),
                'description' => $payload['description'] ?? null,
                'team_size' => (int) ($payload['team_size'] ?? 1),
                'contact_person' => $payload['contact_person'],
                'contact_phone' => $payload['contact_phone'],
                'contact_email' => $payload['contact_email'],
                'regions' => $payload['regions'] ?? [],
                'availability_status' => $payload['availability_status'] ?? BrigadeStatuses::AVAILABILITY_AVAILABLE,
                'verification_status' => BrigadeStatuses::PROFILE_DRAFT,
            ]);

            if (!empty($payload['specializations'])) {
                $this->syncSpecializations($brigade, $payload['specializations']);
            }

            return [
                'user' => $user,
                'brigade' => $brigade->load(['specializations', 'members', 'documents']),
                'token' => $this->tokenIssuer->issue($user, [
                    'guard' => 'api_brigade',
                    'brigade_id' => (int) $brigade->id,
                    'request' => request(),
                ]),
            ];
        });
    }

    public function authenticate(array $credentials): ?array
    {
        Auth::shouldUse('api_brigade');

        if (!Auth::validate($credentials)) {
            return null;
        }

        /** @var User|null $user */
        $user = Auth::getLastAttempted();

        if (!$user?->is_active) {
            return null;
        }

        $brigade = $user?->brigadeProfile;

        if (!$user || !$brigade) {
            return null;
        }

        $token = $this->tokenIssuer->issue($user, [
            'guard' => 'api_brigade',
            'brigade_id' => (int) $brigade->id,
            'request' => request(),
        ]);

        return [
            'user' => $user,
            'brigade' => $brigade->load(['specializations', 'members', 'documents']),
            'token' => $token,
        ];
    }

    public function getOwnedBrigade(User $user): BrigadeProfile
    {
        return $user->brigadeProfile()->with(['specializations', 'members', 'documents'])->firstOrFail();
    }

    public function syncSpecializations(BrigadeProfile $brigade, array $specializations): void
    {
        DB::transaction(function () use ($brigade, $specializations): void {
            $ids = collect($specializations)
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(function (string $name): int {
                    $normalized = trim($name);
                    $model = BrigadeSpecialization::firstOrCreate(
                        ['slug' => Str::slug($normalized)],
                        ['name' => $normalized]
                    );

                    return $model->id;
                })
                ->values()
                ->all();

            $indexingPaused = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused();
            $queue = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue::class);
            if (!$indexingPaused) {
                foreach (DB::table('brigade_profile_specialization')->where('brigade_id', $brigade->id)->select('id')->lazyById(50) as $pivot) {
                    $queue->queueAfterCommit('brigades', 'brigade_specialization_link', (int) $pivot->id);
                }
            }
            $brigade->specializations()->sync($ids);
            if (!$indexingPaused) {
                foreach (DB::table('brigade_profile_specialization')->where('brigade_id', $brigade->id)->select('id')->lazyById(50) as $pivot) {
                    $queue->queueAfterCommit('brigades', 'brigade_specialization_link', (int) $pivot->id);
                }
                $queue->queueAfterCommit('brigades', 'brigade_profile', (int) $brigade->id);
            }
        });
    }

    public function createAssignmentFromInvitation(BrigadeInvitation $invitation): BrigadeProjectAssignment
    {
        if ($invitation->status !== BrigadeStatuses::INVITATION_ACCEPTED) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invitation' => trans_message('brigades.invitation_invalid_status')]);
        }
        return BrigadeProjectAssignment::firstOrCreate(
            [
                'brigade_id' => $invitation->brigade_id,
                'project_id' => $invitation->project_id,
                'source_type' => BrigadeInvitation::class,
                'source_id' => $invitation->id,
            ],
            [
                'contractor_organization_id' => $invitation->contractor_organization_id,
                'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
                'starts_at' => $invitation->starts_at,
                'notes' => $invitation->message,
            ]
        );
    }

    public function respondToInvitation(User $actor, int $invitationId, string $status): BrigadeInvitation
    {
        return DB::transaction(function () use ($actor, $invitationId, $status): BrigadeInvitation {
            $brigade = $this->getOwnedBrigade($actor);
            $invitation = $brigade->invitations()->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            $this->assertPendingInvitation($invitation);
            if (!in_array($status, [BrigadeStatuses::INVITATION_ACCEPTED, BrigadeStatuses::INVITATION_DECLINED], true)) {
                throw new \InvalidArgumentException('invitation_status_invalid');
            }
            $invitation->update(['status' => $status]);
            if ($status === BrigadeStatuses::INVITATION_ACCEPTED) {
                $this->createAssignmentFromInvitation($invitation);
            }

            return $invitation->load(['project', 'contractorOrganization']);
        });
    }

    public function cancelInvitation(User $actor, int $organizationId, int $invitationId): BrigadeInvitation
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$actor->organizations()->where('organizations.id', $organizationId)->wherePivot('is_active', true)->exists()) {
            throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('brigades.forbidden'));
        }

        return DB::transaction(function () use ($actor, $organizationId, $invitationId): BrigadeInvitation {
            $invitation = BrigadeInvitation::query()->where('contractor_organization_id', $organizationId)
                ->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            $authorization = app(\App\Domain\Authorization\Services\AuthorizationService::class);
            $canCancel = $authorization->canCurrent($actor, 'brigades.invitations.cancel', ['organization_id' => $organizationId]);
            if (!$canCancel && $invitation->project_id !== null) {
                $canCancel = $authorization->canCurrent($actor, 'brigades.invitations.cancel', [
                    'organization_id' => $organizationId, 'project_id' => $invitation->project_id, 'strict_project_scope' => true,
                ]);
            }
            if (!$canCancel) {
                throw new \Illuminate\Auth\Access\AuthorizationException(trans_message('brigades.forbidden'));
            }
            $this->assertPendingInvitation($invitation);
            $invitation->update(['status' => BrigadeStatuses::INVITATION_CANCELLED]);

            return $invitation->load(['project', 'contractorOrganization']);
        });
    }

    private function assertPendingInvitation(BrigadeInvitation $invitation): void
    {
        if ($invitation->status !== BrigadeStatuses::INVITATION_PENDING
            || ($invitation->expires_at !== null && $invitation->expires_at->isPast())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['invitation' => trans_message('brigades.invitation_invalid_status')]);
        }
    }

    public function createAssignmentFromResponse(BrigadeResponse $response): BrigadeProjectAssignment
    {
        return BrigadeProjectAssignment::firstOrCreate(
            [
                'brigade_id' => $response->brigade_id,
                'project_id' => $response->request->project_id,
                'source_type' => BrigadeResponse::class,
                'source_id' => $response->id,
            ],
            [
                'contractor_organization_id' => $response->request->contractor_organization_id,
                'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
                'notes' => $response->cover_message,
            ]
        );
    }

    private function makeUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug !== '' ? $baseSlug : 'brigade';
        $counter = 1;

        while (BrigadeProfile::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
