<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('knowledge_categories') || ! Schema::hasTable('knowledge_articles')) {
            return;
        }

        $exitCode = Artisan::call('knowledge-hub:seed-initial-content');

        if ($exitCode !== 0) {
            throw new RuntimeException('Knowledge hub initial content seed failed.');
        }
    }

    public function down(): void
    {
    }
};
