<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('report_files', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
}; 