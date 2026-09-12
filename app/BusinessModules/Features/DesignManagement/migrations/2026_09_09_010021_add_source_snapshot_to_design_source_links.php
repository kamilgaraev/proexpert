<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('design_source_links', function (Blueprint $table): void { $table->jsonb('source_snapshot')->nullable()->after('source_sheet_id'); }); }
    public function down(): void { Schema::table('design_source_links', function (Blueprint $table): void { $table->dropColumn('source_snapshot'); }); }
};
