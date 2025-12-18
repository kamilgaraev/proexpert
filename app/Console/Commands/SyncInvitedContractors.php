<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Contractor;
use Carbon\Carbon;

class SyncInvitedContractors extends Command
{
    protected $signature = 'contractors:sync-invited {--chunk=100 : Количество подрядчиков за итерацию}';

    protected $description = 'Синхронизирует данные подрядчиков типа invited_organization с актуальными данными их исходных организаций';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $this->info('Начата синхронизация данных подрядчиков…');

        $totalUpdated = 0;

        Contractor::invitedOrganizations()
            ->with('sourceOrganization')
            ->chunk($chunk, function ($contractors) use (&$totalUpdated) {
                foreach ($contractors as $contractor) {
                    $src = $contractor->sourceOrganization;
                    if (!$src) {
                        continue;
                    }

                    // Составляем массив данных для синхронизации
                    $syncData = [
                        'name'            => $src->name,
                        'contact_person'  => $src->legal_name,
                        'phone'           => $src->phone,
                        'email'           => $src->email,
                        'legal_address'   => $src->address,
                        'inn'             => $src->tax_number,
                        'kpp'             => $src->registration_number ? substr($src->registration_number, 0, 9) : null,
                        'updated_at'      => now(),
                        'last_sync_at'    => now(),
                    ];

                    // Оставляем только изменившиеся поля
                    $dirty = array_filter($syncData, function ($value, $key) use ($contractor) {
                        return $value !== $contractor->$key;
                    }, ARRAY_FILTER_USE_BOTH);

                    if (!empty($dirty)) {
                        $contractor->fill($dirty);
                        $contractor->save();
                        $totalUpdated++;
                    }
                }
            });

        $this->info("Синхронизация завершена. Обновлено подрядчиков: {$totalUpdated}");
        Log::info('SyncInvitedContractors finished', ['updated' => $totalUpdated]);

        return Command::SUCCESS;
    }
}
