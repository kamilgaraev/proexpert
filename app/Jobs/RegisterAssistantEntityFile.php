<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RegisterAssistantEntityFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $fileId) {}

    public function handle(AssistantDocumentService $documents): void
    {
        Cache::lock('assistant-file:'.$this->fileId, 180)->block(5, function () use ($documents): void {
            $file = File::query()->find($this->fileId);
            if ($file === null) {
                return;
            }
            $document = $documents->registerFile($file);
            ProcessAssistantDocument::dispatch((int) $document->id)->afterCommit();
        });
    }
}
