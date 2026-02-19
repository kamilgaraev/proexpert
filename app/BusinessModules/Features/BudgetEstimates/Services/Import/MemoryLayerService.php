<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\ImportMemory;
use Illuminate\Support\Facades\Log;

class MemoryLayerService
{
    public function findMatch(array $headers, int $organizationId, string $format): ?array
    {
        if (empty($headers)) {
            return null;
        }

        $signature = $this->buildSignature($headers);

        $memory = ImportMemory::where('organization_id', $organizationId)
            ->where('file_format', $format)
            ->where('signature', $signature)
            ->orderByDesc('success_count')
            ->first();

        if (!$memory) {
            return null;
        }

        $memory->increment('usage_count');
        $memory->update(['last_used_at' => now()]);

        Log::info("[MemoryLayer] Hit for org={$organizationId}, sig={$signature}, success_count={$memory->success_count}");

        return [
            'column_mapping'  => $memory->column_mapping,
            'section_hints'   => $memory->section_hints,
            'header_row'      => $memory->header_row,
            'memory_id'       => $memory->id,
            'confidence'      => min(1.0, $memory->success_count / 5),
        ];
    }

    public function remember(
        array $headers,
        array $columnMapping,
        int $organizationId,
        int $userId,
        string $format,
        ?int $headerRow = null,
        ?array $sectionHints = null
    ): ImportMemory {
        $signature = $this->buildSignature($headers);

        $memory = ImportMemory::firstOrNew([
            'organization_id' => $organizationId,
            'signature'       => $signature,
        ]);

        if ($memory->exists) {
            $memory->success_count++;
            $memory->usage_count++;
        } else {
            $memory->user_id          = $userId;
            $memory->file_format      = $format;
            $memory->original_headers = $headers;
            $memory->success_count    = 1;
            $memory->usage_count      = 1;
        }

        $memory->column_mapping = $columnMapping;
        $memory->section_hints  = $sectionHints;
        $memory->header_row     = $headerRow;
        $memory->last_used_at   = now();
        $memory->save();

        Log::info("[MemoryLayer] Saved memory for org={$organizationId}, sig={$signature}");

        return $memory;
    }

    public function feedback(int $memoryId, bool $wasCorrect): void
    {
        $memory = ImportMemory::find($memoryId);

        if (!$memory) {
            return;
        }

        if (!$wasCorrect) {
            $memory->success_count = max(0, $memory->success_count - 2);
            $memory->save();
            Log::info("[MemoryLayer] Negative feedback for memory_id={$memoryId}");
        }
    }

    public function listForOrganization(int $organizationId): array
    {
        return ImportMemory::where('organization_id', $organizationId)
            ->orderByDesc('success_count')
            ->orderByDesc('last_used_at')
            ->limit(50)
            ->get()
            ->map(fn($m) => [
                'id'             => $m->id,
                'file_format'    => $m->file_format,
                'headers'        => $m->original_headers,
                'success_count'  => $m->success_count,
                'usage_count'    => $m->usage_count,
                'last_used_at'   => $m->last_used_at?->toIso8601String(),
            ])
            ->all();
    }

    private function buildSignature(array $headers): string
    {
        $normalized = array_map(
            fn($h) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$h))),
            $headers
        );
        sort($normalized);
        return md5(implode('|', $normalized));
    }
}
