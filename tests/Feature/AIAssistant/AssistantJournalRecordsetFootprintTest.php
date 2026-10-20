<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotFootprint;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AssistantJournalRecordsetFootprintTest extends TestCase
{
    private const RECORDSET = 'pg_catalog.jsonb_to_recordset(CAST(? AS pg_catalog.jsonb)) AS journal_visibility(project_id bigint, performing_organization_id bigint)';

    public function test_closed_native_recordset_proves_only_the_direct_relation(): void
    {
        $project = $this->project();
        $proof = $this->capture($project);
        self::assertSame([(int) $project->id], array_map(static fn (object $row): int => (int) $row->id, $proof['value']));
        self::assertSame(['public.projects'], $proof['relations'], $proof['reason'] ?? '');
    }

    public function test_qualified_recordset_is_not_rebound_to_public_function_or_input_type(): void
    {
        $project = $this->project();
        self::assertNull(DB::selectOne("SELECT to_regprocedure('public.jsonb_to_recordset(pg_catalog.jsonb)') AS present")->present);
        self::assertNull(DB::selectOne("SELECT to_regtype('public.jsonb') AS present")->present);
        DB::statement('CREATE FUNCTION public.jsonb_to_recordset(pg_catalog.jsonb) RETURNS SETOF record LANGUAGE sql STABLE AS $$ SELECT p.id, p.organization_id FROM public.projects p WHERE (SELECT COUNT(*) FROM public.users) >= 0 $$');
        DB::statement('CREATE DOMAIN public.jsonb AS pg_catalog.jsonb CHECK (false)');
        try {
            DB::statement('SET LOCAL search_path TO public, pg_catalog');
            $native = $this->capture($project);
            self::assertSame([(int) $project->id], array_map(static fn (object $row): int => (int) $row->id, $native['value']));
            self::assertSame(['public.projects'], $native['relations'], $native['reason'] ?? '');
            foreach (['jsonb_to_recordset', 'public.jsonb_to_recordset'] as $function) {
                $proof = $this->capture($project, str_replace('pg_catalog.jsonb_to_recordset', $function, self::RECORDSET));
                self::assertNull($proof['relations']);
                self::assertSame('unsupported_function:jsonb_to_recordset', $proof['reason']);
            }
        } finally {
            DB::statement('DROP DOMAIN public.jsonb');
            DB::statement('DROP FUNCTION public.jsonb_to_recordset(pg_catalog.jsonb)');
        }
    }

    public function test_custom_output_types_and_other_record_definitions_remain_unproven(): void
    {
        $project = $this->project();
        DB::statement('CREATE FUNCTION public.assistant_recordset_domain_guard(bigint) RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT COUNT(*) >= 0 FROM public.users $$');
        DB::statement('CREATE DOMAIN public.assistant_recordset_id AS bigint CHECK (public.assistant_recordset_domain_guard(VALUE))');
        try {
            foreach ([
                str_replace('performing_organization_id bigint)', 'performing_organization_id public.assistant_recordset_id)', self::RECORDSET),
                str_replace('CAST(? AS pg_catalog.jsonb)', 'CAST(? AS jsonb)', self::RECORDSET),
                str_replace('CAST(? AS pg_catalog.jsonb)', 'CAST(CAST(? AS text) AS pg_catalog.jsonb)', self::RECORDSET),
                str_replace('AS journal_visibility(', 'AS other_visibility(', self::RECORDSET),
                str_replace('performing_organization_id bigint)', 'performing_organization_id bigint, extra bigint)', self::RECORDSET),
            ] as $recordset) {
                $proof = $this->capture($project, $recordset);
                self::assertNull($proof['relations']);
                self::assertSame('unsupported_function:jsonb_to_recordset', $proof['reason']);
            }
        } finally {
            DB::statement('DROP DOMAIN public.assistant_recordset_id');
            DB::statement('DROP FUNCTION public.assistant_recordset_domain_guard(bigint)');
        }
    }

    public function test_one_closed_call_does_not_approve_a_second_or_nested_unsafe_call(): void
    {
        $project = $this->project();
        DB::statement('CREATE FUNCTION public.assistant_recordset_payload(pg_catalog.jsonb) RETURNS pg_catalog.jsonb LANGUAGE sql STABLE AS $$ SELECT $1 WHERE (SELECT COUNT(*) FROM public.users) >= 0 $$');
        try {
            $unsafe = str_replace('AS journal_visibility(', 'AS other_visibility(', self::RECORDSET);
            $bindings = [$this->pairs($project), $this->pairs($project)];
            $proof = app(AssistantStatusSnapshotFootprint::class)->capture(fn (): array => DB::select($this->sql(self::RECORDSET).' AND EXISTS (SELECT 1 FROM '.$unsafe.')', $bindings));
            self::assertNull($proof['relations']);
            self::assertSame('unsupported_function:jsonb_to_recordset', $proof['reason']);
            $nested = str_replace('CAST(? AS pg_catalog.jsonb)', 'public.assistant_recordset_payload(CAST(? AS pg_catalog.jsonb))', self::RECORDSET);
            $proof = $this->capture($project, $nested);
            self::assertNull($proof['relations']);
            self::assertSame('unsupported_function:jsonb_to_recordset', $proof['reason']);
        } finally {
            DB::statement('DROP FUNCTION public.assistant_recordset_payload(pg_catalog.jsonb)');
        }
    }

    private function project(): Project
    {
        Queue::fake();

        return Model::withoutEvents(fn (): Project => Project::factory()->create(['is_archived' => false]));
    }

    private function capture(Project $project, string $recordset = self::RECORDSET): array
    {
        return app(AssistantStatusSnapshotFootprint::class)->capture(fn (): array => DB::select($this->sql($recordset), [$this->pairs($project)]));
    }

    private function sql(string $recordset): string
    {
        return 'SELECT p.id FROM public.projects AS p WHERE (p.id, p.organization_id) IN (SELECT project_id, performing_organization_id FROM '.$recordset.')';
    }

    private function pairs(Project $project): string
    {
        return json_encode([['project_id' => (int) $project->id, 'performing_organization_id' => (int) $project->organization_id]], JSON_THROW_ON_ERROR);
    }
}
