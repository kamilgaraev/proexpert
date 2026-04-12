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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class BrigadeWorkflowService
{
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
                'token' => JWTAuth::claims(['brigade_id' => $brigade->id])->fromUser($user),
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
        $brigade = $user?->brigadeProfile;

        if (!$user || !$brigade) {
            return null;
        }

        $token = JWTAuth::claims(['brigade_id' => $brigade->id])->fromUser($user);

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

        $brigade->specializations()->sync($ids);
    }

    public function createAssignmentFromInvitation(BrigadeInvitation $invitation): BrigadeProjectAssignment
    {
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
