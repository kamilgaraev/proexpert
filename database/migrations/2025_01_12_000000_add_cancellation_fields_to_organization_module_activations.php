<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('expires_at');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('cancellation_reason');
            $table->json('refund_details')->nullable()->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('organization_module_activations', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'refund_amount', 'refund_details']);
        });
    }
};
