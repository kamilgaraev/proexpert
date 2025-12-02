<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('payment_documents.populate_recipient_org_id.start');

        // Обновляем документы с прямой связью через payee_organization_id
        $updated1 = DB::table('payment_documents')
            ->whereNotNull('payee_organization_id')
            ->whereNull('recipient_organization_id')
            ->update([
                'recipient_organization_id' => DB::raw('payee_organization_id'),
                'updated_at' => now(),
            ]);

        Log::info('payment_documents.populate_recipient_org_id.direct', [
            'updated' => $updated1,
        ]);

        // Обновляем документы через подрядчика (payee_contractor_id)
        $documentsWithContractor = DB::table('payment_documents')
            ->whereNotNull('payee_contractor_id')
            ->whereNull('recipient_organization_id')
            ->get();

        $updated2 = 0;
        foreach ($documentsWithContractor as $doc) {
            $contractor = DB::table('contractors')
                ->where('id', $doc->payee_contractor_id)
                ->whereNotNull('source_organization_id')
                ->first();

            if ($contractor && $contractor->source_organization_id) {
                DB::table('payment_documents')
                    ->where('id', $doc->id)
                    ->update([
                        'recipient_organization_id' => $contractor->source_organization_id,
                        'updated_at' => now(),
                    ]);
                $updated2++;
            }
        }

        Log::info('payment_documents.populate_recipient_org_id.via_contractor', [
            'updated' => $updated2,
        ]);

        // Обновляем документы через contractor_id (для обратной совместимости)
        $documentsWithContractorId = DB::table('payment_documents')
            ->whereNotNull('contractor_id')
            ->whereNull('recipient_organization_id')
            ->whereNull('payee_contractor_id')
            ->get();

        $updated3 = 0;
        foreach ($documentsWithContractorId as $doc) {
            $contractor = DB::table('contractors')
                ->where('id', $doc->contractor_id)
                ->whereNotNull('source_organization_id')
                ->first();

            if ($contractor && $contractor->source_organization_id) {
                DB::table('payment_documents')
                    ->where('id', $doc->id)
                    ->update([
                        'recipient_organization_id' => $contractor->source_organization_id,
                        'updated_at' => now(),
                    ]);
                $updated3++;
            }
        }

        Log::info('payment_documents.populate_recipient_org_id.via_contractor_id', [
            'updated' => $updated3,
        ]);

        Log::info('payment_documents.populate_recipient_org_id.completed', [
            'total_updated' => $updated1 + $updated2 + $updated3,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не удаляем данные, только очищаем recipient_organization_id
        DB::table('payment_documents')
            ->whereNotNull('recipient_organization_id')
            ->update([
                'recipient_organization_id' => null,
                'updated_at' => now(),
            ]);
    }
};

