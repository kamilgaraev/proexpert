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
        if (! Schema::hasColumn('organizations', 'system_key')) {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->string('system_key')->nullable()->unique();
            });
        }

        if (! DB::table('organizations')->where('system_key', 'platform_media_library')->exists()) {
            DB::table('organizations')->insert([
                'system_key' => 'platform_media_library',
                'name' => 'МОСТ — медиатека сайта',
                'description' => 'Служебная организация для хранения материалов сайта. Без сотрудников и клиентских проектов.',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Platform media ownership must be preserved. Use a forward migration to change it.');
    }
};
