<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditPhaseBInvariantService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterCredential;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ImmutableAuditReadinessEndpointTest extends TestCase
{
    private const SECRET = 'v2-test-secret-4f6b9c20-8d31-47ae-b571-53d8412a';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('legal_archive.audit_writer_secret', self::SECRET);
        $this->app->instance(ImmutableAuditPhaseBInvariantService::class, new ImmutableAuditPhaseBInvariantService(static fn (): array => [
            'sequence' => true, 'allocator' => true, 'writer_guard_function' => true,
            'writer_guard_trigger' => true, 'append_only_function' => true, 'append_only_trigger' => true,
            'sequence_sync_function' => true, 'sequence_sync_trigger' => true,
            'aggregate_index' => true, 'legacy_index' => true,
        ]));
        Schema::create('immutable_audit_rollout', function (Blueprint $table): void {
            $table->boolean('singleton')->primary();
            $table->string('phase');
            $table->unsignedInteger('writer_version');
            $table->string('writer_credential_hash', 64)->nullable();
        });
    }

    public function test_readiness_keeps_traffic_closed_until_phase_b(): void
    {
        $credential = new ImmutableAuditWriterCredential;
        DB::table('immutable_audit_rollout')->insert([
            'singleton' => true,
            'phase' => 'phase_a',
            'writer_version' => 1,
            'writer_credential_hash' => $credential->fingerprint(self::SECRET),
        ]);

        $this->get('/up')->assertOk();
        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('ready', false);
        DB::table('immutable_audit_rollout')->update(['phase' => 'phase_b', 'writer_version' => 2]);
        $this->getJson('/ready')->assertOk()->assertJsonPath('ready', true);
    }

    public function test_readiness_fails_closed_when_writer_secret_is_missing(): void
    {
        config()->set('legal_archive.audit_writer_secret', '');

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('reason', 'writer_secret_invalid');
    }

    public function test_semantically_neutered_writer_guard_fingerprint_keeps_traffic_closed(): void
    {
        $this->app->instance(ImmutableAuditPhaseBInvariantService::class, new ImmutableAuditPhaseBInvariantService(static fn (): array => [
            'sequence' => true, 'allocator' => true, 'writer_guard_function' => false,
            'writer_guard_trigger' => true, 'append_only_function' => true, 'append_only_trigger' => true,
            'sequence_sync_function' => true, 'sequence_sync_trigger' => true,
            'aggregate_index' => true, 'legacy_index' => true,
        ]));
        $credential = new ImmutableAuditWriterCredential;
        DB::table('immutable_audit_rollout')->insert([
            'singleton' => true,
            'phase' => 'phase_b',
            'writer_version' => 2,
            'writer_credential_hash' => $credential->fingerprint(self::SECRET),
        ]);

        $this->getJson('/ready')
            ->assertStatus(503)
            ->assertJsonPath('reason', 'immutable_audit_writer_guard_invalid');
    }
}
