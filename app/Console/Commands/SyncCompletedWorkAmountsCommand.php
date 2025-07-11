<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompletedWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCompletedWorkAmountsCommand extends Command
{
    protected $signature = 'completed-works:sync-amounts {--chunk=500 : Кол-во записей в одном чанке}';

    protected $description = 'Заполнить price и total_amount для выполненных работ, где они отсутствуют.';

    public function handle(): int
    {
        $chunk = (int)$this->option('chunk');

        $this->info('Start syncing completed works amounts…');

        $updated = 0;
        CompletedWork::where(function ($q) {
                $q->whereNull('price')->orWhereNull('total_amount');
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$updated) {
                foreach ($rows as $work) {
                    $origPrice = $work->price;
                    $origTotal = $work->total_amount;

                    if ($work->quantity <= 0) {
                        continue; // пропускаем, чтобы не делить на ноль
                    }

                    if (is_null($work->price) && !is_null($work->total_amount)) {
                        $work->price = round($work->total_amount / $work->quantity, 2);
                    }

                    if (is_null($work->total_amount) && !is_null($work->price)) {
                        $work->total_amount = round($work->price * $work->quantity, 2);
                    }

                    if ($work->price !== $origPrice || $work->total_amount !== $origTotal) {
                        $work->save();
                        $updated++;
                    }
                }
            });

        $this->info("Updated rows: {$updated}");
        Log::info('[SyncCompletedWorkAmountsCommand] finished', ['updated' => $updated]);

        return self::SUCCESS;
    }
} 