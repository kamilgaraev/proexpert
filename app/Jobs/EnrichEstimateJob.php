<?php

namespace App\Jobs;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Classification\ItemClassificationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeMatchingService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\ImportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EnrichEstimateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // 20 minutes for AI/Matching
    public int $tries = 3;
    public $queue = 'imports';

    public function __construct(
        public int $estimateId,
        public ?string $sessionId = null
    ) {}

    public function handle(
        ItemClassificationService $classifier,
        NormativeMatchingService $matcher
    ): void
    {
        Log::info("[EnrichEstimateJob] Started for estimate {$this->estimateId}");
        
        $session = $this->sessionId ? ImportSession::find($this->sessionId) : null;
        
        if ($session) {
            $session->update([
                'status' => 'enriching',
                'stats' => array_merge($session->stats ?? [], ['message' => 'Enriching estimate with AI & Normatives...'])
            ]);
        }

        $query = EstimateItem::where('estimate_id', $this->estimateId)
            // Filter only items that need refinement
            ->where(function($q) {
                 $q->whereNull('normative_rate_id')
                   ->orWhere('is_manual', true); // Assuming manual items need classification
            });

        $totalItems = $query->count();
        $processed = 0;
        
        // Chunking
        $query->chunkById(50, function ($items) use ($classifier, $matcher, &$processed, $totalItems, $session) {
            
            $batchClassification = [];
            
            foreach ($items as $item) {
                // 1. Normative Matching
                if ($item->normative_rate_code && !$item->normative_rate_id) {
                    $match = $matcher->findByCode($item->normative_rate_code, ['fallback_to_name' => true, 'name' => $item->name]);
                    
                    if ($match && isset($match['normative'])) {
                        // Apply match
                        $enrichedData = $matcher->fillFromNormative($match['normative'], $item->toArray());
                        // unset ID to avoid primary key conflict if toArray includes it, though fill() should handle it safe usually
                        unset($enrichedData['id'], $enrichedData['estimate_id'], $enrichedData['created_at'], $enrichedData['updated_at']);
                        
                        $item->update($enrichedData);
                        $item->refresh(); // Reload
                    }
                }
                
                // 2. Prepare for AI Classification
                // If item type is generic or default, lets try to classify
                // Assuming 'work' is default.
                if (!$item->normative_rate_id) {
                     $batchClassification[] = [
                         'id' => $item->id,
                         'code' => $item->normative_rate_code ?? '',
                         'name' => $item->name,
                         'unit' => $item->metadata['original_unit'] ?? null,
                         'price' => (float)$item->unit_price
                     ];
                }
                
                $processed++;
            }
            
            // 3. AI Batch Execution
            if (!empty($batchClassification)) {
                $results = $classifier->classifyBatch($batchClassification);
                
                foreach ($batchClassification as $index => $candidate) {
                    $result = $results[$index] ?? null;
                    if ($result) {
                        EstimateItem::where('id', $candidate['id'])->update([
                            'item_type' => $result->itemType,
                            // 'work_type_id' => ... if classifier returned it
                        ]);
                    }
                }
            }
            
            // Update Progress
            if ($session && $totalItems > 0 && $processed % 50 === 0) {
                 $progress = 100 * ($processed / $totalItems);
                 // We are in 'enriching' phase, maybe show it as 100% or separate progress?
                 // Let's rely on message.
                 $session->update([
                     'stats' => array_merge($session->stats ?? [], [
                         'enrichment_progress' => round($progress, 1),
                         'processed_items' => $processed
                     ])
                 ]);
            }
        });

        Log::info("[EnrichEstimateJob] Finished for estimate {$this->estimateId}");
        
        if ($session) {
            $session->update([
                'status' => 'completed',
                'stats' => array_merge($session->stats ?? [], [
                    'enrichment_progress' => 100, 
                    'message' => 'Enrichment completed.'
                ])
            ]);
        }
    }
}
