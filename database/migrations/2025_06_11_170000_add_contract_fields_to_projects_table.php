<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('customer_organization')->nullable()->after('customer');
            $table->string('customer_representative')->nullable()->after('customer_organization');
            $table->string('contract_number')->nullable()->after('customer_representative');
            $table->date('contract_date')->nullable()->after('contract_number');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['customer_organization', 'customer_representative', 'contract_number', 'contract_date']);
        });
    }
}; 