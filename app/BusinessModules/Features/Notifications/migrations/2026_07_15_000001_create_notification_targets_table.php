<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const INTERFACES = ['admin', 'lk', 'mobile', 'customer'];

    public function up(): void
    {
        $usesIdentitySequence = DB::getDriverName() === 'pgsql';

        Schema::create('notification_targets', function (Blueprint $table) use ($usesIdentitySequence): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('interface', 20);

            if ($usesIdentitySequence) {
                $table->bigInteger('sequence')->generatedAs()->always();
            } else {
                $table->bigInteger('sequence');
            }

            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->string('websocket_status', 20)->default('pending');
            $table->timestampTz('websocket_delivered_at')->nullable();
            $table->text('websocket_last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['notification_id', 'interface']);
            $table->index('notification_id');
            $table->index(['interface', 'dismissed_at', 'read_at']);
            $table->index(['interface', 'websocket_status']);
            $table->index(['interface', 'sequence']);
        });

        Schema::create('notification_interface_cursors', function (Blueprint $table): void {
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('interface', 20);
            $table->bigInteger('latest_sequence');
            $table->timestampsTz();

            $table->primary(['recipient_user_id', 'interface']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_targets ADD CONSTRAINT notification_targets_interface_check '
                ."CHECK (interface IN ('admin', 'lk', 'mobile', 'customer'))"
            );
            DB::statement(
                'ALTER TABLE notification_interface_cursors ADD CONSTRAINT notification_interface_cursors_interface_check '
                ."CHECK (interface IN ('admin', 'lk', 'mobile', 'customer'))"
            );
            DB::statement('LOCK TABLE notifications IN SHARE ROW EXCLUSIVE MODE');
        }

        $nextSequence = 1;

        DB::table('notifications')
            ->select(['id', 'data', 'read_at'])
            ->orderBy('id')
            ->chunkById(500, static function (Collection $notifications) use ($usesIdentitySequence, &$nextSequence): void {
                $targets = [];
                $timestamp = now();

                foreach ($notifications as $notification) {
                    $data = $notification->data;

                    if (! is_string($data)) {
                        continue;
                    }

                    try {
                        $data = json_decode($data, false, flags: JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        continue;
                    }

                    if (! $data instanceof \stdClass) {
                        continue;
                    }

                    $interface = 'admin';

                    if (property_exists($data, 'interface')) {
                        if (! is_string($data->interface)) {
                            continue;
                        }

                        $interface = $data->interface;
                    }

                    if (! in_array($interface, self::INTERFACES, true)) {
                        continue;
                    }

                    $target = [
                        'id' => (string) Str::uuid(),
                        'notification_id' => $notification->id,
                        'interface' => $interface,
                        'read_at' => $notification->read_at,
                        'websocket_status' => 'pending',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    if (! $usesIdentitySequence) {
                        $target['sequence'] = $nextSequence++;
                    }

                    $targets[] = $target;
                }

                if ($targets !== []) {
                    DB::table('notification_targets')->insertOrIgnore($targets);
                }
            }, 'id');

        $cursorBackfill = DB::table('notification_targets')
            ->join('notifications', 'notifications.id', '=', 'notification_targets.notification_id')
            ->where('notifications.notifiable_type', User::class)
            ->selectRaw(
                'notifications.notifiable_id AS recipient_user_id, notification_targets.interface, '
                .'MAX(notification_targets.sequence) AS latest_sequence, '
                .'CURRENT_TIMESTAMP AS created_at, CURRENT_TIMESTAMP AS updated_at'
            )
            ->groupBy('notifications.notifiable_id', 'notification_targets.interface');

        DB::table('notification_interface_cursors')->insertUsing(
            ['recipient_user_id', 'interface', 'latest_sequence', 'created_at', 'updated_at'],
            $cursorBackfill
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_interface_cursors');
        Schema::dropIfExists('notification_targets');
    }
};
