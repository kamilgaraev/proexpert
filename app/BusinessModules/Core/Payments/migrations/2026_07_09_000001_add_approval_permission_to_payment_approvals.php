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
        Schema::table('payment_approvals', function (Blueprint $table): void {
            $table->string('approval_permission', 150)->nullable()->after('approval_role');
            $table->index('approval_permission', 'payment_approvals_permission_idx');
        });

        $this->dropApprovalRoleNotNull();
    }

    public function down(): void
    {
        $this->restoreApprovalRoleNotNull();

        Schema::table('payment_approvals', function (Blueprint $table): void {
            $table->dropIndex('payment_approvals_permission_idx');
            $table->dropColumn('approval_permission');
        });
    }

    private function dropApprovalRoleNotNull(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_approvals ALTER COLUMN approval_role DROP NOT NULL');

            return;
        }

        Schema::table('payment_approvals', function (Blueprint $table): void {
            $table->string('approval_role')->nullable()->change();
        });
    }

    private function restoreApprovalRoleNotNull(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("UPDATE payment_approvals SET approval_role = 'legacy' WHERE approval_role IS NULL");
            DB::statement('ALTER TABLE payment_approvals ALTER COLUMN approval_role SET NOT NULL');

            return;
        }

        Schema::table('payment_approvals', function (Blueprint $table): void {
            $table->string('approval_role')->nullable(false)->change();
        });
    }
};
