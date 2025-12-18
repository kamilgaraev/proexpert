<?php

namespace App\BusinessModules\Core\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetPaymentDocumentSequences extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:reset-document-sequences
                            {--force : Force reset without confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Удалить и пересоздать sequences для номеров платежных документов';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('Это удалит все sequences для номеров платежных документов. Продолжить?')) {
                $this->info('Операция отменена.');
                return 0;
            }
        }

        $this->info('Удаление существующих sequences...');
        
        // Удаляем все sequences для платежных документов
        $sequences = DB::select("
            SELECT relname 
            FROM pg_class 
            WHERE relname LIKE 'payment_doc_seq_%' 
            AND relkind = 'S'
        ");
        
        $count = 0;
        foreach ($sequences as $seq) {
            DB::statement("DROP SEQUENCE IF EXISTS " . $seq->relname);
            $count++;
            $this->line("  - Удалена sequence: {$seq->relname}");
        }
        
        $this->info("Удалено sequences: {$count}");
        
        $this->info('При следующем создании документа sequences будут автоматически пересозданы с правильными номерами.');
        $this->info('✅ Готово!');
        
        return 0;
    }
}

