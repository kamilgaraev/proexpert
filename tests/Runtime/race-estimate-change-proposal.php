<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $proposalId, $target, $actorId, $barrier] = $argv;
while (microtime(true) < (float) $barrier) {
    usleep(1000);
}
$won = DB::transaction(function () use ($proposalId, $target, $actorId): bool {
    $updated = DB::table('estimate_change_proposal_states')->where('proposal_id', $proposalId)->where('status', 'proposed')->update([
        'status' => $target, 'version' => DB::raw('version + 1'), 'terminal_actor_id' => (int) $actorId, 'updated_at' => now(),
    ]) === 1;
    if ($updated) {
        DB::table('estimate_change_proposal_transitions')->insert([
            'proposal_id' => $proposalId, 'actor_id' => (int) $actorId, 'from_status' => 'proposed', 'to_status' => $target, 'metadata' => '{}', 'created_at' => now(),
        ]);
    }

    return $updated;
});
fwrite(STDOUT, $won ? '1' : '0');
