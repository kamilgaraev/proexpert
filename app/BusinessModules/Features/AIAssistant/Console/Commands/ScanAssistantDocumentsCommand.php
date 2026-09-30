<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Console\Commands;

use App\Jobs\ScanAssistantDocuments;
use App\Models\Organization;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ScanAssistantDocumentsCommand extends Command
{
    protected $signature = 'ai-assistant:documents-scan {organization_id?} {--batch=50}';
    protected $description = 'Queue bounded archive discovery for organizations with an active assistant.';

    public function handle(OrganizationEntitlementService $entitlements): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $organizationId = $this->argument('organization_id');
        if ($batch === false || $batch < 1 || $batch > 100 || ($organizationId !== null && (filter_var($organizationId, FILTER_VALIDATE_INT) === false || (int) $organizationId < 1))) {
            $this->error('Invalid organization or batch size.');
            return self::FAILURE;
        }
        $dispatched = Cache::lock('assistant-document-organization-scan-dispatch', 120)->get(function () use ($batch, $organizationId, $entitlements): int {
            $query = Organization::query()->where('is_active', true)->orderBy('id');
            $cursor = $organizationId === null ? (int) Cache::get('assistant-document-organization-scan-cursor', 0) : 0;
            $organizations = $organizationId === null ? $query->where('id', '>', $cursor)->limit($batch)->get(['id']) : $query->whereKey((int) $organizationId)->get(['id']);
            $count = 0;
            foreach ($organizations as $organization) {
                if ($entitlements->getEffectiveModules((int) $organization->id)->contains('slug', 'ai-assistant')) {
                    ScanAssistantDocuments::dispatch((int) $organization->id);
                    $count++;
                }
                if ($organizationId === null) Cache::forever('assistant-document-organization-scan-cursor', (int) $organization->id);
            }
            if ($organizationId === null && $organizations->count() < $batch) Cache::forever('assistant-document-organization-scan-cursor', 0);
            return $count;
        });
        $this->line('Queued archive scans: '.(int) $dispatched);
        return self::SUCCESS;
    }
}
