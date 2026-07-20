<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void {
            $table->string('notification_type', 255)->nullable()->after('delivery_key');
            $table->jsonb('notification_payload')->nullable()->after('notification_type');
            $table->unsignedInteger('attempt_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['notification_type', 'notification_payload', 'attempt_count']);
        });
    }
};
