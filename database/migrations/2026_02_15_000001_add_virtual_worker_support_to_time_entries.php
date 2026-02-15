<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('worker_type')->default('user')->after('user_id')->comment('Тип работника: user, virtual, brigade');
            $table->string('worker_name')->nullable()->after('worker_type')->comment('ФИО виртуального работника или название бригады');
            $table->integer('worker_count')->nullable()->after('worker_name')->comment('Количество человек в бригаде');
            $table->decimal('volume_completed', 10, 2)->nullable()->after('hours_worked')->comment('Объем выполненных работ');
            
            $table->foreignId('user_id')->nullable()->change();
            
            $table->index(['worker_type', 'work_date']);
            $table->index(['worker_name', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['worker_type', 'worker_name', 'worker_count', 'volume_completed']);
            $table->dropIndex(['time_entries_worker_type_work_date_index']);
            $table->dropIndex(['time_entries_worker_name_work_date_index']);
        });
    }
};
