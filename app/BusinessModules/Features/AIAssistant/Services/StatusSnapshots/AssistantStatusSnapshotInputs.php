<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AssistantStatusSnapshotInputs
{
    public function __construct(private readonly OrganizationEntitlementService $entitlements) {}

    public function fingerprint(User $actor, int $organizationId, KnowledgeSurface $surface, string $section, ?string $ip, AuthorizationService $authorization): ?string
    {
        $release = config('ai-assistant.status_snapshot_release') ?: getenv('MOST_RELEASE_SHA');
        if (! is_string($release) || preg_match('/^[a-f0-9]{40}$/D', $release) !== 1) { return null; }
        $roles = $authorization->getUserRoles($actor)->sortBy('id')->values()->toArray();
        $modules = $this->entitlements->getEffectiveModuleSlugs($organizationId);
        sort($modules);
        $connection = DB::connection();
        $configuration = [config('app.env'), config('app.timezone'), config('authorization'), config('ai-assistant'), config('commercial_offers'),
            $connection->getDatabaseName(), $connection->getConfig('host'), $connection->getConfig('port'), $connection->getConfig('username')];
        $catalogFiles = [];
        foreach (['Packages', 'ModuleList', 'RoleDefinitions'] as $directory) {
            $path = config_path($directory);
            if (! is_dir($path)) { continue; }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                    $catalogFiles[$file->getPathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($catalogFiles);

        return hash('sha256', serialize([$release, (int) $actor->id, $organizationId, $surface->value, $section, $ip,
            now()->toDateString(), $roles, $modules, $configuration, $catalogFiles]));
    }

    public function validUntil(string $capturedAt, int $ttlSeconds, int $organizationId, int $actorId): string
    {
        $captured = CarbonImmutable::parse($capturedAt)->utc();
        $deadline = $captured->addSeconds($ttlSeconds);
        $midnight = $captured->setTimezone((string) config('app.timezone', 'UTC'))->addDay()->startOfDay()->utc();
        if ($midnight->lt($deadline)) { $deadline = $midnight; }
        $window = max(0, (int) config('commercial_offers.renewal_processing_window_minutes', 5));
        $row = DB::selectOne(
            'SELECT MIN(boundary) AS boundary FROM ('
            .'SELECT expires_at AS boundary FROM legal_document_access_grants WHERE organization_id = ? OR subject_organization_id = ? OR subject_user_id = ? '
            .'UNION ALL SELECT expires_at FROM user_role_assignments WHERE user_id = ? '
            .'UNION ALL SELECT trial_ends_at FROM organization_package_subscriptions WHERE organization_id = ? '
            .'UNION ALL SELECT current_period_end_at FROM organization_package_subscriptions WHERE organization_id = ? '
            .'UNION ALL SELECT current_period_end_at + (? * interval \'1 minute\') FROM organization_package_subscriptions WHERE organization_id = ? '
            .'UNION ALL SELECT grace_ends_at FROM organization_commercial_accounts WHERE organization_id = ?'
            .') AS deadlines WHERE boundary > ?::timestamptz',
            [$organizationId, $organizationId, $actorId, $actorId, $organizationId, $organizationId, $window, $organizationId, $organizationId, $captured->toIso8601String()],
        );
        if (is_string($row?->boundary)) {
            $boundary = CarbonImmutable::parse($row->boundary)->utc();
            if ($boundary->lt($deadline)) { $deadline = $boundary; }
        }

        return $deadline->toIso8601String();
    }

    public function decisionsMatch(array $decisions, User $actor, AuthorizationService $authorization): bool
    {
        foreach ($decisions as $decision) {
            if (! is_array($decision) || ($decision['actor_id'] ?? null) !== (int) $actor->id
                || ! is_string($decision['permission'] ?? null) || ! is_bool($decision['allowed'] ?? null)
                || (array_key_exists('context', $decision) && $decision['context'] !== null && ! is_array($decision['context']))
                || ! $this->serializableContext($decision['context'] ?? null)) { return false; }
            if ($authorization->canCurrent($actor, $decision['permission'], $decision['context']) !== $decision['allowed']) { return false; }
        }

        return true;
    }

    public function isUnexpired(mixed $deadline): bool
    {
        if (! is_string($deadline)) { return false; }
        try {
            return CarbonImmutable::parse($deadline)->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    public function age(mixed $generatedAt): ?int
    {
        if (! is_string($generatedAt)) { return null; }
        try {
            $seconds = now()->getTimestamp() - CarbonImmutable::parse($generatedAt)->getTimestamp();

            return $seconds >= 0 ? $seconds : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function serializableContext(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) { return true; }
        if (! is_array($value)) { return false; }
        foreach ($value as $entry) {
            if (! $this->serializableContext($entry)) { return false; }
        }

        return true;
    }
}
