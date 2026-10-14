<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompletenessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignPackageBlockingIssueQuery;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use App\Models\User;

final readonly class AssistantProjectDesignReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_design_readiness_projection';
    public const INTENT_PATTERN = '/(?:проектн[а-яё]*\s+комплект|комплект[а-яё]*.*(?:выпуск|выпущ|пир)|пир.*(?:комплект|выпуск)|нормконтрол|(?:раздел[а-яё]*|комплектност[а-яё]*|состав)[\s\S]*(?:(?<![\p{L}\p{N}_])рд(?![\p{L}\p{N}_])|рабоч[а-яё]*\s+документ)|(?:(?<![\p{L}\p{N}_])рд(?![\p{L}\p{N}_])|рабоч[а-яё]*\s+документ)[\s\S]*(?:не\s+хватает|недоста|комплектност|состав|раздел))/iu';

    public function __construct(AssistantDataAccessPolicy $access, private DesignCompletenessService $completeness)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match(self::INTENT_PATTERN, $query) === 1
            && preg_match('/создай|измени|удали|выпусти|согласуй|отправь|оплат|оплач|плат[её]ж|сч[её]т|бюджет|потрач|выручк|затрат|при[её]мк|журнал|охран[а-яё]*\s+труд|происшеств|табел|техник|rfi|вопрос[а-яё]*\s+проектировщик|критическ[а-яё]*\s+путь/iu', $query) !== 1;
    }

    protected function domain(): string { return 'design'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'design_package');
        if ($query === null) { return ['available' => false]; }
        $counts = self::statusCounts($query);
        $pending = (clone $query)->whereNotIn('status', ['issued', 'archived']);
        $examples = [];
        $packages = (clone $pending)->select(['id', 'organization_id', 'project_id', 'title', 'project_stage', 'status', 'composition_status', 'composition_revision_id', 'planned_issue_date'])
            ->selectRaw("CASE WHEN octet_length(metadata::text) <= 262144 THEN metadata ELSE '{}'::jsonb END AS metadata,
                octet_length(metadata::text) <= 262144 AS metadata_bounded")
            ->withOpenBlockingCount()->orderBy('id')->limit(20)->get();
        foreach ($packages as $package) {
            if (! $package instanceof DesignPackage) { continue; }
            $example = $package->only(['id', 'title', 'status', 'composition_status']);
            $example['status'] = (string) $package->getRawOriginal('status');
            $example['project_stage'] = (string) $package->getRawOriginal('project_stage');
            $example['open_blocking_comments'] = DesignPackageWorkflow::openBlockingCommentsCount($package);
            $example['readiness_available'] = $this->bounded($package);
            if ($example['readiness_available']) {
                $package->load(['sections', 'artifacts.currentVersion', 'latestCompletenessCheck']);
                $activeArtifacts = $package->artifacts->filter(static fn ($artifact): bool => DesignPackageWorkflow::isActiveArtifact($package, $artifact));
                $sections = $package->sections->filter(static fn ($section): bool => ($section->metadata['composition_active'] ?? true) !== false);
                $example['sections'] = $sections->count();
                $example['required_empty_sections'] = $sections->filter(static fn ($section): bool => $section->required
                    && ! $activeArtifacts->contains(static fn ($artifact): bool => (int) $artifact->section_id === (int) $section->id))->count();
                $example['missing_composition'] = $this->access->canCurrentPermission($actor, $organizationId, 'design-management.documents.view')
                    && (($example['project_stage'] !== 'bim' && ! $package->artifacts->contains(static fn ($artifact): bool => $artifact->getRawOriginal('artifact_type') === 'model'))
                        || $this->access->canCurrentPermission($actor, $organizationId, 'design-management.models.view'))
                    ? $this->missingComposition($package) : ['available' => false];
                if ($example['missing_composition']['available']) {
                    $example['required_empty_sections'] = $example['missing_composition']['sections_total'];
                }
                $example['artifacts'] = $activeArtifacts->count();
                $example['current_documents'] = $activeArtifacts->filter(static fn ($artifact): bool => $artifact->getRawOriginal('artifact_type') !== 'model' && $artifact->currentVersion !== null)->count();
                $example['sheets'] = DesignDocumentSheet::query()->where('package_id', $package->id)->count();
                $check = $package->latestCompletenessCheck;
                $example['check'] = $check === null ? null : ['status' => (string) $check->getRawOriginal('status'),
                    'fresh' => $this->completeness->isFreshForPackage($package, $check), 'blocking' => (int) $check->blocking_count, 'warnings' => (int) $check->warning_count];
                $example['next_action'] = DesignPackageWorkflow::availableActions($package)[0] ?? null;
            }
            $examples[] = $example;
        }
        return ['available' => true, 'status_counts' => $counts, 'total' => array_sum($counts), 'not_issued' => $pending->count(), 'examples' => $examples];
    }

    private function missingComposition(DesignPackage $package): array
    {
        foreach ([...$package->sections, ...$package->artifacts] as $child) {
            if ((int) $child->organization_id !== (int) $package->organization_id
                || (int) $child->project_id !== (int) $package->project_id || (int) $child->package_id !== (int) $package->id) { return ['available' => false]; }
        }
        foreach ($package->artifacts as $artifact) {
            $version = $artifact->currentVersion;
            if ($version !== null && ((int) $version->organization_id !== (int) $package->organization_id
                || (int) $version->project_id !== (int) $package->project_id || (int) $version->artifact_id !== (int) $artifact->id)) { return ['available' => false]; }
            if ($artifact->section_id !== null && ! $package->sections->contains('id', $artifact->section_id)) { return ['available' => false]; }
        }
        $revision = null;
        if ($package->composition_revision_id !== null) {
            $revision = DesignCompositionRevision::query()->whereKey($package->composition_revision_id)->where('package_id', $package->id)
                ->where('organization_id', $package->organization_id)->where('project_id', $package->project_id)->where('status', 'approved')->first();
            if ($revision === null || $revision->exclusions()->where(static fn ($q) => $q->where('organization_id', '!=', $package->organization_id)
                ->orWhere('project_id', '!=', $package->project_id)->orWhere('package_id', '!=', $package->id))->exists()) { return ['available' => false]; }
        }
        foreach ($package->sections as $section) {
            $section->setRelation('artifacts', $package->artifacts->filter(static fn ($artifact): bool => (int) $artifact->section_id === (int) $section->id));
        }
        $scope = new \App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope($package);
        $selected = collect($scope->selectedItems() ?? [])->keyBy('code');
        $missingSections = (new \App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredSectionRule)->check($package);
        $missingDocuments = (new \App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredCurrentArtifactRule)->check($package);
        $sections = array_map(static function ($result) use ($package, $selected): array {
            $code = (string) ($result->metadata['section_code'] ?? '');
            $section = $package->sections->firstWhere('code', $code);
            return ['code' => $code, 'title' => (string) ($selected->get($code)['title'] ?? $section?->title ?? $code), 'message' => $result->message];
        }, array_slice($missingSections, 0, 20));

        return ['available' => true, 'approved_revision_id' => $revision?->id, 'sections_total' => count($missingSections),
            'documents_total' => count($missingDocuments), 'sections' => $sections,
            'documents' => array_map(static fn ($result): array => ['section_code' => $result->metadata['section_code'] ?? null,
                'document_code' => $result->metadata['document_code'] ?? null, 'message' => $result->message], array_slice($missingDocuments, 0, 20))];
    }

    private function bounded(DesignPackage $package): bool
    {
        if (! $package->getAttribute('metadata_bounded')) { return false; }
        $queries = [$package->sections()->getQuery(), $package->artifacts()->getQuery(), $package->reviewComments()->getQuery(),
            DesignArtifactVersion::query()->whereHas('artifact', static fn ($q) => $q->where('package_id', $package->id)),
            DesignDocumentSheet::query()->where('package_id', $package->id), DesignPackageBlockingIssueQuery::forPackage($package),
            $package->latestCompletenessCheck()->getQuery()];
        if ($package->composition_revision_id !== null) {
            $revisionQuery = DesignCompositionRevision::query()->whereKey($package->composition_revision_id)->where('package_id', $package->id);
            $revisionSize = (clone $revisionQuery)->selectRaw('COALESCE(MAX(octet_length(to_jsonb(design_composition_revisions)::text)), 0) AS bytes')->first();
            if ((int) $revisionSize?->getAttribute('bytes') > 1048576) { return false; }
            $revision = $revisionQuery->select(['id', 'package_id'])->first();
            if ($revision !== null) { $queries[] = $revision->exclusions()->getQuery(); }
        }
        foreach ($queries as $query) {
            $table = $query->getModel()->getTable();
            $size = (clone $query)->reorder()->select([])->selectRaw('COUNT(*) AS total, COALESCE(SUM(octet_length(to_jsonb('.$table.')::text)), 0) AS bytes')->first();
            if ($size === null || (int) $size->getAttribute('total') > 500 || (int) $size->getAttribute('bytes') > 1048576) { return false; }
        }
        return strlen(json_encode($package->metadata, JSON_THROW_ON_ERROR)) <= 262144;
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.design_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        if (! $data['available']) { return implode("\n", [...$lines, trans_message('ai_assistant_operational.design_unavailable')]); }
        $lines[] = trans_message('ai_assistant_operational.design_counts', ['total' => $data['total'], 'pending' => $data['not_issued'],
            'issued' => $data['status_counts']['issued'] ?? 0, 'shown' => count($data['examples'])]);
        if ($data['total'] === 0) { $lines[] = trans_message('ai_assistant_operational.design_no_expected_composition'); }
        foreach ($data['examples'] as $example) {
            $lines[] = '- #'.$example['id'].' '.self::text($example['title']).'; '.trans_message('design_management.statuses.packages.'.$example['status']);
            $lines[] = trans_message('ai_assistant_operational.design_stage', ['stage' => trans_message('design_management.project_stages.'.$example['project_stage'])]);
            $lines[] = trans_message('ai_assistant_operational.design_blocking', ['count' => $example['open_blocking_comments'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            if (! $example['readiness_available']) { $lines[] = trans_message('ai_assistant_operational.design_limit'); continue; }
            $lines[] = trans_message('ai_assistant_operational.design_content', ['sections' => $example['sections'], 'empty' => $example['required_empty_sections'], 'artifacts' => $example['artifacts'],
                'documents' => $example['current_documents'], 'sheets' => $example['sheets']]);
            $missing = $example['missing_composition'];
            if (! $missing['available']) { $lines[] = trans_message('ai_assistant_operational.design_composition_unavailable'); }
            else {
                $lines[] = trans_message($missing['approved_revision_id'] === null ? 'ai_assistant_operational.design_composition_legacy' : 'ai_assistant_operational.design_composition_approved');
                $lines[] = trans_message('ai_assistant_operational.design_missing_composition', ['sections' => $missing['sections_total'], 'documents' => $missing['documents_total']]);
                foreach ($missing['sections'] as $section) { $lines[] = '- '.self::text($section['code']).' '.self::text($section['title']).': '.self::text($section['message']); }
                foreach ($missing['documents'] as $document) { $lines[] = '- '.self::text((string) $document['section_code']).' / '.self::text((string) $document['document_code']).': '.self::text($document['message']); }
            }
            $check = $example['check'];
            if ($check === null) { $lines[] = trans_message('ai_assistant_operational.design_not_checked'); }
            elseif (! $check['fresh']) { $lines[] = trans_message('ai_assistant_operational.design_stale'); }
            else { $lines[] = trans_message('ai_assistant_operational.design_check', ['status' => in_array($check['status'], ['ready', 'warning', 'blocked'], true)
                ? trans_message('design_management.completeness_statuses.'.$check['status']) : trans_message('ai_assistant_operational.not_recorded'), 'blocking' => $check['blocking'], 'warnings' => $check['warnings']]); }
            if ($example['next_action'] !== null) {
                $lines[] = trans_message('ai_assistant_operational.design_step', ['action' => trans_message('design_management.actions.'.$example['next_action'])]);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.design_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
