<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ProjectParticipantInvitationConcurrencyTest extends TestCase
{
    public function test_two_organizations_cannot_accept_one_invitation_concurrently(): void
    {
        $ownerOrganization = Organization::factory()->create();
        $owner = $this->organizationUser($ownerOrganization, 'owner-race@example.test');
        $project = Project::factory()->create(['organization_id' => $ownerOrganization->id]);
        $invitation = ProjectParticipantInvitation::query()->create([
            'project_id' => $project->id,
            'organization_id' => $ownerOrganization->id,
            'invited_by_user_id' => $owner->id,
            'role' => ProjectOrganizationRole::OBSERVER->value,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'organization_name' => 'Concurrent observer',
        ]);
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $firstUser = $this->organizationUser($firstOrganization, 'first-race@example.test');
        $secondUser = $this->organizationUser($secondOrganization, 'second-race@example.test');

        DB::commit();

        $suffix = bin2hex(random_bytes(5));
        $lockKey = random_int(100000, 2000000000);
        $function = "invitation_accept_barrier_{$suffix}";
        $trigger = "invitation_accept_barrier_{$suffix}";
        $processes = [];
        $advisoryLocked = false;

        try {
            DB::unprepared(
                "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN ".
                "IF NEW.project_id = {$project->id} THEN PERFORM pg_advisory_xact_lock({$lockKey}); END IF; ".
                'RETURN NEW; END $$',
            );
            DB::unprepared(
                "CREATE TRIGGER {$trigger} BEFORE INSERT ON project_organization ".
                "FOR EACH ROW EXECUTE FUNCTION {$function}()",
            );
            DB::select('SELECT pg_advisory_lock(?)', [$lockKey]);
            $advisoryLocked = true;

            $processes[] = $this->startWorker($invitation->token, $firstUser->id, $firstOrganization->id);
            $processes[] = $this->startWorker($invitation->token, $secondUser->id, $secondOrganization->id);
            $backendPids = array_map(static fn (array $worker): int => $worker['backend_pid'], $processes);
            $this->waitUntilBothWorkersAreLocked($backendPids);

            DB::select('SELECT pg_advisory_unlock(?)', [$lockKey]);
            $advisoryLocked = false;
            $results = array_map(fn (array $worker): array => $this->finishWorker($worker), $processes);
            $processes = [];

            $successes = array_values(array_filter($results, static fn (array $result): bool => $result['success'] === true));
            $conflicts = array_values(array_filter($results, static fn (array $result): bool => $result['success'] === false));

            self::assertCount(1, $successes);
            self::assertCount(1, $conflicts);
            self::assertSame(409, $conflicts[0]['code']);
            self::assertSame(1, DB::table('project_organization')
                ->where('project_id', $project->id)
                ->whereIn('organization_id', [$firstOrganization->id, $secondOrganization->id])
                ->count());
            self::assertSame(
                (int) $successes[0]['organization_id'],
                (int) $invitation->fresh()->accepted_organization_id_snapshot,
            );
        } finally {
            if ($advisoryLocked) {
                DB::select('SELECT pg_advisory_unlock(?)', [$lockKey]);
            }
            foreach ($processes as $worker) {
                $this->terminateWorker($worker);
            }
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON project_organization");
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }
    }

    private function organizationUser(Organization $organization, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return $user;
    }

    /** @return array{process: resource, pipes: array<int, resource>, backend_pid: int} */
    private function startWorker(string $token, int $userId, int $organizationId): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $connection = (array) config('database.connections.'.config('database.default'));
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'MOST_INVITATION_ACCEPT_PAYLOAD' => json_encode([
                'token' => $token,
                'user_id' => $userId,
                'organization_id' => $organizationId,
            ], JSON_THROW_ON_ERROR),
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/Auth/invitation_accept_worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start invitation acceptance worker.');
        }

        fclose($pipes[0]);
        $ready = fgets($pipes[1]);

        if (! is_string($ready) || preg_match('/^READY:([1-9][0-9]*)\R$/D', $ready, $matches) !== 1) {
            throw new RuntimeException('Invitation worker did not become ready: '.stream_get_contents($pipes[2]));
        }

        return ['process' => $process, 'pipes' => $pipes, 'backend_pid' => (int) $matches[1]];
    }

    /** @param array{process: resource, pipes: array<int, resource>, backend_pid: int} $worker */
    private function finishWorker(array $worker): array
    {
        $line = fgets($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0 || ! is_string($line)) {
            throw new RuntimeException('Invitation worker failed: '.$stderr);
        }

        return (array) json_decode(trim($line), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @param list<int> $backendPids */
    private function waitUntilBothWorkersAreLocked(array $backendPids): void
    {
        $deadline = microtime(true) + 10;

        do {
            $locked = DB::table('pg_stat_activity')
                ->whereIn('pid', $backendPids)
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($locked === 2) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Concurrent invitation workers did not reach the lock barrier.');
    }

    /** @param array{process: resource, pipes: array<int, resource>, backend_pid: int} $worker */
    private function terminateWorker(array $worker): void
    {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
    }
}
