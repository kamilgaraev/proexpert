<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmContactIdentity;
use App\BusinessModules\Features\Crm\Models\CrmContactPoint;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Models\CrmMergeEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CrmDuplicateService
{
    public function __construct(
        private readonly CrmTextNormalizer $normalizer,
        private readonly CrmTimelineService $timeline
    ) {
    }

    public function candidates(int $organizationId, string $entityType, array $filters = []): array
    {
        return match ($entityType) {
            'companies' => $this->companyCandidates($organizationId, $filters),
            'contacts' => $this->contactCandidates($organizationId, $filters),
            default => [],
        };
    }

    public function hintsForRow(int $organizationId, string $entityType, array $values): array
    {
        $filters = [
            'inn' => $values['inn'] ?? null,
            'email' => $values['email'] ?? null,
            'phone' => $values['phone'] ?? null,
            'name' => $values['name'] ?? $values['full_name'] ?? null,
        ];

        return array_slice($this->candidates($organizationId, $entityType, $filters), 0, 5);
    }

    public function merge(
        int $organizationId,
        string $entityType,
        string $masterId,
        string $duplicateId,
        ?string $reason,
        ?int $actorUserId
    ): Model {
        if ($masterId === $duplicateId) {
            throw ValidationException::withMessages([
                'duplicate_id' => trans_message('crm.merge.same_record'),
            ]);
        }

        return DB::transaction(function () use (
            $organizationId,
            $entityType,
            $masterId,
            $duplicateId,
            $reason,
            $actorUserId
        ): Model {
            return match ($entityType) {
                'companies' => $this->mergeCompanies($organizationId, $masterId, $duplicateId, $reason, $actorUserId),
                'contacts' => $this->mergeContacts($organizationId, $masterId, $duplicateId, $reason, $actorUserId),
                default => throw ValidationException::withMessages([
                    'entity_type' => trans_message('crm.merge.unsupported_entity'),
                ]),
            };
        });
    }

    private function companyCandidates(int $organizationId, array $filters): array
    {
        $query = CrmCompany::query()
            ->forOrganization($organizationId)
            ->whereNull('merged_into_id')
            ->with(['primaryContact'])
            ->limit(20);

        $inn = $this->normalizer->inn((string) ($filters['inn'] ?? ''));
        $email = $this->normalizer->email((string) ($filters['email'] ?? ''));
        $phone = $this->normalizer->phone((string) ($filters['phone'] ?? ''));
        $name = $this->normalizer->text((string) ($filters['name'] ?? $filters['q'] ?? ''));

        $query->where(function ($inner) use ($inn, $email, $phone, $name): void {
            if ($inn !== null) {
                $inner->orWhere('inn', $inn);
            }

            if ($email !== null) {
                $inner->orWhereRaw('LOWER(email) = ?', [$email]);
            }

            if ($phone !== null) {
                $inner->orWhere('phone', 'like', '%' . $phone . '%');
            }

            if ($name !== null) {
                $inner->orWhere('name', 'ilike', '%' . $name . '%')
                    ->orWhere('legal_name', 'ilike', '%' . $name . '%');
            }
        });

        return $query->get()
            ->map(fn (CrmCompany $company): array => [
                'entity_type' => 'companies',
                'id' => $company->id,
                'title' => $company->name,
                'subtitle' => $company->inn ?: $company->email ?: $company->phone,
                'score' => $this->scoreCompany($company, $inn, $email, $phone, $name),
                'status' => $company->status,
            ])
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    private function contactCandidates(int $organizationId, array $filters): array
    {
        $query = CrmContact::query()
            ->forOrganization($organizationId)
            ->whereNull('merged_into_id')
            ->with(['company'])
            ->limit(20);

        $email = $this->normalizer->email((string) ($filters['email'] ?? ''));
        $phone = $this->normalizer->phone((string) ($filters['phone'] ?? ''));
        $name = $this->normalizer->text((string) ($filters['name'] ?? $filters['full_name'] ?? $filters['q'] ?? ''));

        $query->where(function ($inner) use ($email, $phone, $name): void {
            if ($email !== null) {
                $inner->orWhereRaw('LOWER(email) = ?', [$email]);
            }

            if ($phone !== null) {
                $inner->orWhere('phone', 'like', '%' . $phone . '%');
            }

            if ($name !== null) {
                $inner->orWhere('full_name', 'ilike', '%' . $name . '%');
            }
        });

        return $query->get()
            ->map(fn (CrmContact $contact): array => [
                'entity_type' => 'contacts',
                'id' => $contact->id,
                'title' => $contact->full_name,
                'subtitle' => $contact->company?->name ?: $contact->email ?: $contact->phone,
                'score' => $this->scoreContact($contact, $email, $phone, $name),
                'status' => $contact->status,
            ])
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    private function mergeCompanies(
        int $organizationId,
        string $masterId,
        string $duplicateId,
        ?string $reason,
        ?int $actorUserId
    ): CrmCompany {
        $master = CrmCompany::query()->forOrganization($organizationId)->findOrFail($masterId);
        $duplicate = CrmCompany::query()->forOrganization($organizationId)->findOrFail($duplicateId);
        $before = $duplicate->toArray();

        CrmContact::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);
        CrmLead::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);
        CrmDeal::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);
        CrmActivity::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);
        CrmContactPoint::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);
        CrmContactIdentity::query()->where('company_id', $duplicate->id)->update(['company_id' => $master->id]);

        $duplicate->update([
            'merged_into_id' => $master->id,
            'status' => 'merged',
            'updated_by_user_id' => $actorUserId,
        ]);
        $duplicate->delete();

        $this->createMergeEvent($organizationId, 'companies', $master->id, $duplicate->id, $before, $master->fresh()->toArray(), $reason, $actorUserId);

        return $master->refresh();
    }

    private function mergeContacts(
        int $organizationId,
        string $masterId,
        string $duplicateId,
        ?string $reason,
        ?int $actorUserId
    ): CrmContact {
        $master = CrmContact::query()->forOrganization($organizationId)->findOrFail($masterId);
        $duplicate = CrmContact::query()->forOrganization($organizationId)->findOrFail($duplicateId);
        $before = $duplicate->toArray();

        CrmLead::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $master->id]);
        CrmDeal::query()->where('primary_contact_id', $duplicate->id)->update(['primary_contact_id' => $master->id]);
        CrmActivity::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $master->id]);
        CrmContactPoint::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $master->id]);
        CrmContactIdentity::query()->where('contact_id', $duplicate->id)->update(['contact_id' => $master->id]);

        $duplicate->update([
            'merged_into_id' => $master->id,
            'status' => 'merged',
            'updated_by_user_id' => $actorUserId,
        ]);
        $duplicate->delete();

        $this->createMergeEvent($organizationId, 'contacts', $master->id, $duplicate->id, $before, $master->fresh()->toArray(), $reason, $actorUserId);

        return $master->refresh();
    }

    private function createMergeEvent(
        int $organizationId,
        string $entityType,
        string $masterId,
        string $duplicateId,
        array $before,
        array $after,
        ?string $reason,
        ?int $actorUserId
    ): void {
        CrmMergeEvent::query()->create([
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'master_id' => $masterId,
            'duplicate_id' => $duplicateId,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'affected_links' => [],
            'created_at' => now(),
        ]);

        $this->timeline->record(
            $organizationId,
            $entityType,
            $masterId,
            'merged',
            trans_message('crm.timeline.merged'),
            $actorUserId,
            ['duplicate_id' => $duplicateId]
        );
    }

    private function scoreCompany(CrmCompany $company, ?string $inn, ?string $email, ?string $phone, ?string $name): int
    {
        $score = 0;

        if ($inn !== null && $company->inn === $inn) {
            $score += 70;
        }

        if ($email !== null && $this->normalizer->email($company->email) === $email) {
            $score += 35;
        }

        if ($phone !== null && $this->normalizer->phone($company->phone) === $phone) {
            $score += 25;
        }

        if ($name !== null && mb_stripos($company->name, $name) !== false) {
            $score += 20;
        }

        return min($score, 100);
    }

    private function scoreContact(CrmContact $contact, ?string $email, ?string $phone, ?string $name): int
    {
        $score = 0;

        if ($email !== null && $this->normalizer->email($contact->email) === $email) {
            $score += 45;
        }

        if ($phone !== null && $this->normalizer->phone($contact->phone) === $phone) {
            $score += 35;
        }

        if ($name !== null && mb_stripos($contact->full_name, $name) !== false) {
            $score += 25;
        }

        return min($score, 100);
    }
}
