<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void { $table->char('lease_token',64)->nullable()->after('lease_expires_at'); $table->index(['status','lease_expires_at']); }); } public function down(): void { Schema::table('legal_document_notification_deliveries', function (Blueprint $table): void { $table->dropIndex(['status','lease_expires_at']); $table->dropColumn('lease_token'); }); } };
