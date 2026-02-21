<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Jobs;

use App\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateEstimateSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // Large estimate snapshots can take time to serialize

    public readonly int $estimateId;

    public function __construct(int $estimateId)
    {
        $this->estimateId = $estimateId;
    }

    public function handle(): void
    {
        try {
            $estimate = Estimate::find($this->estimateId);
            
            if (!$estimate) {
                return;
            }

            Log::info("Starting snapshot generation for estimate {$this->estimateId}");

            // 1. Извлекаем данные через Query Builder (Raw Arrays). 
            // Отказ от Eloquent Hydration дает ускорение на 1-2 порядка.
            $sections = DB::table('estimate_sections')
                ->where('estimate_id', $this->estimateId)
                ->orderBy('sort_order')
                ->get()
                ->map(fn($item) => (array) $item)
                ->toArray();

            $items = DB::table('estimate_items')
                ->where('estimate_id', $this->estimateId)
                ->orderBy('position_number')
                ->get()
                ->map(fn($item) => (array) $item)
                ->toArray();

            $itemIds = array_column($items, 'id');
            
            // Если позиций нет, нет смысла искать связи
            if (empty($itemIds)) {
                $resourcesByItemId = [];
                $totalsByItemId = [];
                $worksByItemId = []; 
            } else {
                // Извлекаем связанные данные оптом
                $resourcesChunks = DB::table('estimate_item_resources')
                    ->whereIn('estimate_item_id', $itemIds)
                    ->get()
                    ->map(fn($r) => (array) $r);
                
                $resourcesByItemId = [];
                foreach ($resourcesChunks as $res) {
                    $resourcesByItemId[$res['estimate_item_id']][] = $res;
                }

                $totalsChunks = DB::table('estimate_item_totals')
                    ->whereIn('estimate_item_id', $itemIds)
                    ->get()
                    ->map(fn($t) => (array) $t);
                    
                $totalsByItemId = [];
                foreach ($totalsChunks as $tot) {
                    $totalsByItemId[$tot['estimate_item_id']][] = $tot;
                }
                
                // Works can be loaded similarly if EstimateItem has 'works' relation
                $worksChunks = DB::table('estimate_item_works')
                    ->whereIn('estimate_item_id', $itemIds)
                    ->get()
                    ->map(fn($w) => (array) $w);
                    
                $worksByItemId = [];
                foreach ($worksChunks as $work) {
                    $worksByItemId[$work['estimate_item_id']][] = $work;
                }
            }

            $workTypes = DB::table('work_types')->pluck('name', 'id')->toArray();
            $measurementUnits = DB::table('measurement_units')->pluck('symbol', 'id')->toArray();

            // 2. Сборка дерева O(N) в памяти ссылками
            $itemsById = [];
            foreach ($items as &$item) {
                $item['resources'] = $resourcesByItemId[$item['id']] ?? [];
                $item['totals'] = $totalsByItemId[$item['id']] ?? [];
                $item['works'] = $worksByItemId[$item['id']] ?? [];
                $item['childItems'] = [];
                
                // Append simple relations
                $item['work_type'] = isset($item['work_type_id']) && isset($workTypes[$item['work_type_id']]) 
                    ? ['id' => $item['work_type_id'], 'name' => $workTypes[$item['work_type_id']]] : null;
                $item['measurement_unit'] = isset($item['measurement_unit_id']) && isset($measurementUnits[$item['measurement_unit_id']])
                    ? ['id' => $item['measurement_unit_id'], 'symbol' => $measurementUnits[$item['measurement_unit_id']]] : null;

                $itemsById[$item['id']] =& $item;
            }

            // Связываем items внутри других items (Sub-items)
            $rootItemsWithSection = [];
            $rootItemsWithoutSection = [];

            foreach ($items as &$item) {
                if (!empty($item['parent_work_id'])) {
                    if (isset($itemsById[$item['parent_work_id']])) {
                        $itemsById[$item['parent_work_id']]['childItems'][] =& $item;
                    }
                } else {
                    if (!empty($item['estimate_section_id'])) {
                        $rootItemsWithSection[$item['estimate_section_id']][] =& $item;
                    } else {
                        $rootItemsWithoutSection[] =& $item;
                    }
                }
            }

            $sectionsById = [];
            foreach ($sections as &$section) {
                $section['items'] = $rootItemsWithSection[$section['id']] ?? [];
                $section['children'] = [];
                $sectionsById[$section['id']] =& $section;
            }

            $tree = [];
            foreach ($sections as &$section) {
                if (!empty($section['parent_section_id'])) {
                    if (isset($sectionsById[$section['parent_section_id']])) {
                        $sectionsById[$section['parent_section_id']]['children'][] =& $section;
                    }
                } else {
                    $tree[] =& $section;
                }
            }

            // Формируем payload (можно отдать только sections + itemsWithoutSection)
            // Но чтобы фронт получал удобный root - завернем в один объект
            $payload = [
                'sections' => $tree,
                'itemsWithoutSection' => $rootItemsWithoutSection
            ];

            // 3. Сохранение в Storage
            $versionTimestamp = now()->getTimestamp();
            
            // Распределяем файлы по папкам организаций, как это делает FileService
            $orgId = $estimate->organization_id;
            $orgPrefix = $orgId ? "org-{$orgId}" : 'shared';
            $fileName = "{$orgPrefix}/estimates/{$this->estimateId}/structure_snapshot_{$versionTimestamp}.json";
            
            // Запись огромного JSON
            Storage::disk('s3')->put($fileName, json_encode($payload, JSON_UNESCAPED_UNICODE));

            // Обновляем структуру
            $oldPath = $estimate->structure_cache_path;
            
            $estimate->structure_cache_path = $fileName;
            $estimate->save();

            // Удаляем старый кэш
            if ($oldPath && $oldPath !== $fileName && Storage::disk('s3')->exists($oldPath)) {
                Storage::disk('s3')->delete($oldPath);
            }

            Log::info("Snapshot generated successfully", [
                'estimate_id' => $this->estimateId,
                'path' => $fileName,
                'items_count' => count($items),
                'memory_usage_mb' => round(memory_get_peak_usage() / 1024 / 1024, 2)
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to generate estimate snapshot json', [
                'estimate_id' => $this->estimateId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
