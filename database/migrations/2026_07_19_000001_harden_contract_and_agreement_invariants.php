<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplementary_agreements', static function (Blueprint $table): void {
            $table->timestampTz('applied_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('application_key')->nullable()->unique();
        });

        DB::table('supplementary_agreements')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, static function ($agreements): void {
                $agreementIds = $agreements->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $eventsByAgreementId = DB::table('contract_state_events')
                    ->select(['triggered_by_id', 'created_at', 'created_by_user_id'])
                    ->where('triggered_by_type', 'App\\Models\\SupplementaryAgreement')
                    ->whereIn('triggered_by_id', $agreementIds)
                    ->whereIn('event_type', ['amended', 'supplementary_agreement_created'])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get()
                    ->unique('triggered_by_id')
                    ->keyBy('triggered_by_id');

                foreach ($agreementIds as $agreementId) {
                    $event = $eventsByAgreementId->get($agreementId);

                    if ($event === null) {
                        continue;
                    }

                    DB::table('supplementary_agreements')
                        ->where('id', $agreementId)
                        ->whereNull('applied_at')
                        ->update([
                            'applied_at' => $event->created_at,
                            'applied_by_user_id' => $event->created_by_user_id,
                            'application_key' => "supplementary-agreement:{$agreementId}",
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('supplementary_agreements', static function (Blueprint $table): void {
            $table->dropUnique(['application_key']);
            $table->dropConstrainedForeignId('applied_by_user_id');
            $table->dropColumn(['applied_at', 'application_key']);
        });
    }
};
