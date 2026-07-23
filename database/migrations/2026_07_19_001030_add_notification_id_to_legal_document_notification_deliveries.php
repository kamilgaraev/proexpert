<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('notification_id')->nullable()->unique()->after('delivery_key');
        });
    }

    public function down(): void
    {
        Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['notification_id']);
            $table->dropColumn('notification_id');
        });
    }
};
