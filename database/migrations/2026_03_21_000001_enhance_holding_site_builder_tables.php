<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            $table->jsonb('published_payload')->nullable()->after('analytics_config');
        });

        Schema::table('site_content_blocks', function (Blueprint $table) {
            $table->jsonb('bindings')->nullable()->after('settings');
        });

        Schema::create('holding_site_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->string('block_key')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('form_payload')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->jsonb('utm_params')->nullable();
            $table->string('source_page')->nullable();
            $table->string('source_url')->nullable();
            $table->string('status')->default('new');
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['holding_site_id', 'status']);
            $table->index(['holding_site_id', 'submitted_at']);
            $table->index(['block_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_site_leads');

        Schema::table('site_content_blocks', function (Blueprint $table) {
            $table->dropColumn('bindings');
        });

        Schema::table('holding_sites', function (Blueprint $table) {
            $table->dropColumn('published_payload');
        });
    }
};
