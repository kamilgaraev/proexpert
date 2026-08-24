<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$payload = json_decode((string) getenv('MOST_INVITATION_ACCEPT_PAYLOAD'), true, 16, JSON_THROW_ON_ERROR);

if (!is_array($payload)) {
    throw new RuntimeException('Invitation worker payload is missing.');
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '20s'");
$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
fwrite(STDOUT, 'READY:'.(string) $backend->pid.PHP_EOL);
fflush(STDOUT);

try {
    $invitation = app(ProjectParticipantInvitationService::class)->acceptByToken(
        (string) $payload['token'],
        User::query()->findOrFail((int) $payload['user_id']),
        Organization::query()->findOrFail((int) $payload['organization_id']),
    );
    $result = [
        'success' => true,
        'status' => $invitation->status,
        'organization_id' => $invitation->accepted_organization_id_snapshot,
    ];
} catch (Throwable $exception) {
    $result = [
        'success' => false,
        'code' => $exception->getCode(),
        'exception' => $exception::class,
    ];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);

