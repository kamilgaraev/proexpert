<?php

declare(strict_types=1);

use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$token = (string) getenv('MOST_INVITATION_DECLINE_TOKEN');
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '20s'");
$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
fwrite(STDOUT, 'READY:'.(string) $backend->pid.PHP_EOL);
fflush(STDOUT);

try {
    $invitation = app(ProjectParticipantInvitationService::class)->declineByToken($token);
    $result = ['success' => true, 'status' => $invitation->status];
} catch (Throwable $exception) {
    $result = [
        'success' => false,
        'code' => $exception->getCode(),
        'exception' => $exception::class,
    ];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
