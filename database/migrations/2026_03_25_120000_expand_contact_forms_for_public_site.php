<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_forms', function (Blueprint $table) {
            $table->string('company_role')->nullable();
            $table->string('company_size')->nullable();
            $table->boolean('consent_to_personal_data')->default(false);
            $table->string('consent_version')->nullable();
            $table->string('page_source')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_forms', function (Blueprint $table) {
            $table->dropColumn([
                'company_role',
                'company_size',
                'consent_to_personal_data',
                'consent_version',
                'page_source',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
            ]);
        });
    }
};
