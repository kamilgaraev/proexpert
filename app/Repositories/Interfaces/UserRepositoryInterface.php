use Illuminate\Support\Collection;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    // ... другие методы ...

    public function findByRoleInOrganization(int $organizationId, string $roleSlug): Collection;
    public function findByRoleInOrganizationPaginated(int $organizationId, string $roleSlug, int $perPage = 15): \Illuminate\Pagination\LengthAwarePaginator;
    public function attachToOrganization(int $userId, int $organizationId): void;
    public function assignRole(int $userId, int $roleId, int $organizationId): void;
    public function revokeRole(int $userId, int $roleId, int $organizationId): bool;
    public function detachFromOrganization(int $userId, int $organizationId): bool;
    public function findByEmail(string $email): ?\App\Models\User;

    /**
     * Check if a user has a specific role within a specific organization.
     *
     * @param int $userId
     * @param int $roleId
     * @param int $organizationId
     * @return bool
     */
    public function hasRoleInOrganization(int $userId, int $roleId, int $organizationId): bool;
} 