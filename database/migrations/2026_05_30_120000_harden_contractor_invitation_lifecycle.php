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
        $this->allowCancelledStatus();
        $this->replaceInvitationUniqueness();

        Schema::table('contractor_invitations', function (Blueprint $table): void {
            if (! Schema::hasColumn('contractor_invitations', 'declined_at')) {
                $table->timestampTz('declined_at')->nullable()->after('accepted_by_user_id');
            }

            if (! Schema::hasColumn('contractor_invitations', 'declined_by_user_id')) {
                $table->unsignedBigInteger('declined_by_user_id')->nullable()->after('declined_at');
                $table->foreign('declined_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('contractor_invitations', 'cancelled_at')) {
                $table->timestampTz('cancelled_at')->nullable()->after('declined_by_user_id');
            }

            if (! Schema::hasColumn('contractor_invitations', 'cancelled_by_user_id')) {
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable()->after('cancelled_at');
                $table->foreign('cancelled_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('contractor_invitations', 'status_reason')) {
                $table->text('status_reason')->nullable()->after('cancelled_by_user_id');
            }
        });
    }

    public function down(): void
    {
        DB::table('contractor_invitations')
            ->where('status', 'cancelled')
            ->update(['status' => 'expired']);

        Schema::table('contractor_invitations', function (Blueprint $table): void {
            if (Schema::hasColumn('contractor_invitations', 'declined_by_user_id')) {
                $table->dropForeign(['declined_by_user_id']);
            }

            if (Schema::hasColumn('contractor_invitations', 'cancelled_by_user_id')) {
                $table->dropForeign(['cancelled_by_user_id']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('contractor_invitations', 'declined_at') ? 'declined_at' : null,
                Schema::hasColumn('contractor_invitations', 'declined_by_user_id') ? 'declined_by_user_id' : null,
                Schema::hasColumn('contractor_invitations', 'cancelled_at') ? 'cancelled_at' : null,
                Schema::hasColumn('contractor_invitations', 'cancelled_by_user_id') ? 'cancelled_by_user_id' : null,
                Schema::hasColumn('contractor_invitations', 'status_reason') ? 'status_reason' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        $this->revertCancelledStatus();
        $this->restoreInvitationUniqueness();
    }

    private function replaceInvitationUniqueness(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE contractor_invitations DROP CONSTRAINT IF EXISTS unique_active_invitation');
            DB::statement('DROP INDEX IF EXISTS unique_active_invitation');
            DB::statement('DROP INDEX IF EXISTS contractor_invitations_pending_pair_unique');
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS contractor_invitations_pending_pair_unique ON contractor_invitations (organization_id, invited_organization_id) WHERE status = 'pending'"
            );
        }

        if ($driver === 'mysql') {
            $indexExists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', 'contractor_invitations')
                ->where('index_name', 'unique_active_invitation')
                ->exists();

            if ($indexExists) {
                DB::statement('ALTER TABLE contractor_invitations DROP INDEX unique_active_invitation');
            }
        }
    }

    private function allowCancelledStatus(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE contractor_invitations DROP CONSTRAINT IF EXISTS contractor_invitations_status_check');
            DB::statement(
                "ALTER TABLE contractor_invitations ADD CONSTRAINT contractor_invitations_status_check CHECK (status IN ('pending', 'accepted', 'declined', 'expired', 'cancelled'))"
            );
        }

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE contractor_invitations MODIFY status ENUM('pending', 'accepted', 'declined', 'expired', 'cancelled') NOT NULL DEFAULT 'pending'"
            );
        }
    }

    private function restoreInvitationUniqueness(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS contractor_invitations_pending_pair_unique');
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS unique_active_invitation ON contractor_invitations (organization_id, invited_organization_id, status)'
            );
        }

        if ($driver === 'mysql') {
            $indexExists = DB::table('information_schema.statistics')
                ->where('table_schema', DB::raw('DATABASE()'))
                ->where('table_name', 'contractor_invitations')
                ->where('index_name', 'unique_active_invitation')
                ->exists();

            if (! $indexExists) {
                DB::statement('ALTER TABLE contractor_invitations ADD UNIQUE unique_active_invitation (organization_id, invited_organization_id, status)');
            }
        }
    }

    private function revertCancelledStatus(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE contractor_invitations DROP CONSTRAINT IF EXISTS contractor_invitations_status_check');
            DB::statement(
                "ALTER TABLE contractor_invitations ADD CONSTRAINT contractor_invitations_status_check CHECK (status IN ('pending', 'accepted', 'declined', 'expired'))"
            );
        }

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE contractor_invitations MODIFY status ENUM('pending', 'accepted', 'declined', 'expired') NOT NULL DEFAULT 'pending'"
            );
        }
    }
};
