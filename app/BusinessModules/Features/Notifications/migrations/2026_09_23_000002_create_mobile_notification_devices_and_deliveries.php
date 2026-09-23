<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('installation_id', 128);
            $table->string('platform', 16);
            $table->string('provider', 16)->default('rustore');
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('last_registered_at')->nullable();
            $table->timestampsTz();
            $table->unique('installation_id');
            $table->index(['provider', 'user_id']);
            $table->index(['user_id', 'platform']);
        });

        Schema::create('notification_device_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('notification_devices')->nullOnDelete();
            $table->string('installation_id', 128);
            $table->char('token_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->string('message_id', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['notification_id', 'installation_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_device_deliveries');
        Schema::dropIfExists('notification_devices');
    }
};
