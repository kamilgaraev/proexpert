<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EloquentEffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class Luna6OperationPinPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_sql_pin_supports_luna6_and_replay_keeps_the_original_operation_model(): void
    {
        $this->seedSettings();
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'draft', 'input_payload' => [], 'state_version' => 0]);
        $operation = (string) Str::uuid();
        $store = new EloquentEffectiveSettingsOperationStore(DB::connection());
        $pair = $store->pinVision($operation, (int) $organization->id, (int) $session->id, 'openai/gpt-6-luna', 'openai/gpt-6-luna');
        self::assertSame('openai/gpt-6-luna', $pair->visionModel);
        self::assertSame('openai/gpt-6-luna', $pair->effective->model('vision'));
        $replay = $store->pinVision($operation, (int) $organization->id, (int) $session->id, null, 'openai/gpt-5.6-luna');
        self::assertSame('openai/gpt-6-luna', $replay->visionModel);
        self::assertSame($pair->effective->snapshotId, $replay->effective->snapshotId);
        self::assertSame(1, DB::table('estimate_generation_ai_operations')->where('correlation_id', $operation)->count());
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
        try {
            DB::transaction(fn () => $store->pinVision((string) Str::uuid(), (int) $organization->id, (int) $session->id, 'openai/gpt-6-sol', 'openai/gpt-6-luna'));
            self::fail('Unapproved model was pinned.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('vision_model_unsupported', $exception->getMessage());
        }
    }

    private function seedSettings(): void
    {
        $adminId = DB::table('system_admins')->insertGetId(['name' => 'Settings migration fixture',
            'email' => 'settings-migration@example.test', 'password' => bcrypt('testing-only-fixture-password'), 'created_at' => now(), 'updated_at' => now()]);
        $snapshot = [
            'schema_version' => 2, 'models' => array_fill_keys(['vision', 'classification', 'normative_matching'], 'openai/gpt-5.6-luna'),
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 45, 'classification' => 30, 'normative_matching' => 20],
            'retries' => ['vision' => 2, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200'],
            'enabled_formats' => ['pdf'], 'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ];
        $hash = SettingsSnapshotHash::calculate($snapshot);
        $id = DB::table('estimate_generation_setting_snapshots')->insertGetId(['scope' => 'global', 'organization_id' => null,
            'version' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
            'daily_budget' => '250.00', 'monthly_budget' => '4000.00', 'currency' => 'RUB', 'created_by_system_admin_id' => $adminId, 'created_at' => now()]);
        DB::table('estimate_generation_setting_snapshot_hashes')->insert(['setting_snapshot_id' => $id, 'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now()]);
        $migration = require base_path('app/BusinessModules/Addons/EstimateGeneration/migrations/2026_10_11_000400_allow_luna6_operation_pins.php');
        $migration->up();
        $migration->up();
        self::assertSame(2, DB::table('estimate_generation_setting_snapshots')->count());
        self::assertSame('openai/gpt-5.6-luna', json_decode(DB::table('estimate_generation_setting_snapshots')->where('id', $id)->value('snapshot'), true, 64, JSON_THROW_ON_ERROR)['models']['vision']);
        self::assertSame(1, DB::table('estimate_generation_setting_audits')->count());
    }
}
