<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\WorkforceManagement\Contracts\WorkforcePersonNameProvider;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class WarehousePersonIdentityResolver
{
    public function __construct(private readonly WorkforcePersonNameProvider $personNameProvider) {}

    /** @return array{name: string, email: ?string} */
    public function resolve(int $organizationId, int $userId, DateTimeInterface $date): array
    {
        return $this->resolveMany($organizationId, [
            $userId => [
                'user_id' => $userId,
                'date' => $date,
            ],
        ])[$userId];
    }

    /**
     * @param  array<int, array{user_id: int, date: DateTimeInterface}>  $references
     * @return array<int, array{name: string, email: ?string}>
     */
    public function resolveMany(int $organizationId, array $references): array
    {
        if ($references === []) {
            return [];
        }

        $userIds = collect($references)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $employeeNames = $this->personNameProvider->employeeNamesAt($organizationId, $references);
        $ownerUserIds = DB::table('organization_user')
            ->where('organization_id', $organizationId)
            ->where('is_owner', true)
            ->where('is_active', true)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->mapWithKeys(static fn (int|string $userId): array => [(int) $userId => true])
            ->all();

        $resolved = [];
        foreach ($references as $referenceId => $reference) {
            $userId = (int) $reference['user_id'];
            $employeeName = $employeeNames[$referenceId] ?? null;
            $user = $users->get($userId);

            if ($employeeName !== null) {
                $resolved[$referenceId] = [
                    'name' => $employeeName,
                    'email' => $user?->email,
                ];

                continue;
            }

            if (isset($ownerUserIds[$userId])) {
                $resolved[$referenceId] = [
                    'name' => trans_message('warehouse_basic.organization_owner'),
                    'email' => null,
                ];

                continue;
            }

            $resolved[$referenceId] = [
                'name' => trim((string) $user?->name) !== ''
                    ? (string) $user->name
                    : trans_message('warehouse_basic.document_person_not_specified'),
                'email' => $user?->email,
            ];
        }

        return $resolved;
    }
}
