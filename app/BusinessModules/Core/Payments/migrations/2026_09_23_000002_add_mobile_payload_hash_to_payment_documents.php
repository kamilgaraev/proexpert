<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_documents', static function (Blueprint $table): void {
            $table->string('mobile_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_documents', static function (Blueprint $table): void {
            $table->dropColumn('mobile_payload_hash');
        });
    }
};
