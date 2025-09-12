<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем зависимые таблицы в правильном порядке
        Schema::dropIfExists('organization_subscription_addons');
        Schema::dropIfExists('organization_one_time_purchases');
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('subscription_addons');
        Schema::dropIfExists('organization_modules');
    }

    public function down(): void
    {
        // В случае отката - эти таблицы можно будет пересоздать через старые миграции
        // Но лучше не делать rollback этой миграции на проде
    }
};
