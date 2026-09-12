<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use Illuminate\Support\Facades\DB;

final class LegacyDesignCompositionBackfillService
{
    public function run(): void
    {
        DB::table('design_packages')->whereNull('composition_revision_id')->orderBy('id')->eachById(function (object $candidate): void {
            DB::transaction(function () use ($candidate): void {
                $package = DB::table('design_packages')->where('id', $candidate->id)->lockForUpdate()->first();
                if ($package === null || $package->composition_revision_id !== null) {
                    return;
                }
                $actorId = (int) ($package->created_by ?: $package->updated_by ?: 0);

                $existing = DB::table('design_composition_revisions')->where('package_id', $package->id)->where('revision_number', 1)->first();
                if ($existing !== null) {
                    DB::table('design_packages')->where('id', $package->id)->update(['composition_revision_id' => $existing->id, 'composition_status' => $existing->status]);

                    return;
                }
                $sections = DB::table('design_package_sections')->where('package_id', $package->id)->orderBy('sort_order')->orderBy('id')
                    ->get(['id', 'code', 'title', 'required', 'metadata'])->map(static function (object $row): array {
                        $metadata = json_decode((string) ($row->metadata ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

                        return [
                            'legacy_section_id' => $row->id, 'code' => $row->code, 'title' => $row->title,
                            'required' => (bool) $row->required,
                            'documents' => is_array($metadata['documents'] ?? null) ? array_values($metadata['documents']) : [],
                        ];
                    })->all();
                $stage = (string) $package->project_stage;
                $status = 'needs_review';
                $composition = ['project_stage' => $stage, 'legacy_snapshot' => ['package_id' => $package->id, 'section_ids' => array_column($sections, 'legacy_section_id'), 'discipline' => $package->discipline]];
                $artifacts = DB::table('design_artifacts')->where('package_id', $package->id)->get();
                $composition['legacy_snapshot']['package'] = (array) $package;
                $composition['legacy_snapshot']['sections'] = DB::table('design_package_sections')->where('package_id', $package->id)->get()->map(static fn (object $row): array => (array) $row)->all();
                $composition['legacy_snapshot']['artifacts'] = $artifacts->map(static fn (object $row): array => (array) $row)->all();
                $composition['legacy_snapshot']['versions'] = DB::table('design_artifact_versions')->whereIn('artifact_id', $artifacts->pluck('id'))->get()->map(static fn (object $row): array => (array) $row)->all();
                if ($stage === 'pd') {
                    $status = 'approved';
                    $composition['sections'] = $sections;
                } elseif ($stage === 'rd' && in_array(mb_strtoupper(trim((string) $package->discipline), 'UTF-8'), ['AR', 'KR', 'OV', 'VK', 'EM', 'SS'], true)) {
                    $status = 'approved';
                    $composition['brand'] = mb_strtoupper(trim((string) $package->discipline), 'UTF-8');
                    $composition['document_groups'] = $sections;
                } else {
                    $composition['items'] = $sections;
                    $composition['conversion_report'] = ['status' => 'needs_review', 'reason' => $stage === 'rd' ? 'ambiguous_discipline' : 'explicit_composition_required'];
                }
                $composition['conversion_report'] ??= ['status' => $status, 'reason' => 'preserved_existing_composition'];
                $composition['conversion_report']['converted_at'] = now()->toIso8601String();
                $json = json_encode($composition, JSON_THROW_ON_ERROR);
                $id = DB::table('design_composition_revisions')->insertGetId(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'package_id' => $package->id, 'revision_number' => 1, 'status' => $status, 'composition' => $json, 'fingerprint' => hash('sha256', $json), 'created_by' => ($actorId ?: null), 'approved_by' => null, 'approved_at' => null, 'needs_review_reason' => $composition['conversion_report']['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('design_packages')->where('id', $package->id)->whereNull('composition_revision_id')->update(['composition_revision_id' => $id, 'composition_status' => $status]);
            });
        });
    }
}
