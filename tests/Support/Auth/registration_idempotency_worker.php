<?php

declare(strict_types=1);

use App\DTOs\Auth\RegisterDTO;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use App\Services\Auth\RegistrationIdempotencyService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$input = json_decode((string) getenv('MOST_REGISTRATION_PAYLOAD'), true, 16, JSON_THROW_ON_ERROR);

if (! is_array($input) || ! is_array($input['payload'] ?? null)) {
    throw new RuntimeException('Registration worker payload is missing.');
}

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '30s'");
$backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
fwrite(STDOUT, 'READY:'.(string) $backend->pid.PHP_EOL);
fflush(STDOUT);

try {
    $result = app(RegistrationIdempotencyService::class)->execute(
        'lk',
        (string) $input['key'],
        $input['payload'],
        static fn (): array => app(JwtAuthService::class)->register(RegisterDTO::fromRequest($input['payload'])),
    );
    $user = $result['user'] ?? null;
    $organization = $result['organization'] ?? null;
    $output = [
        'success' => ($result['success'] ?? false) === true,
        'user_id' => $user instanceof User ? $user->id : null,
        'organization_id' => $organization instanceof Organization ? $organization->id : null,
        'replay' => (bool) ($result['idempotent_replay'] ?? false),
    ];
} catch (Throwable $exception) {
    $output = [
        'success' => false,
        'code' => $exception->getCode(),
        'exception' => $exception::class,
    ];
}

fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
fflush(STDOUT);
