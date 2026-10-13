<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryExhaustedDocumentCleanup;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RetryDocumentCleanupCommand extends Command
{
    protected $signature = 'estimate-generation:retry-document-cleanup {organization} {project} {session} {debt}
        {identity} {request} {actor} {reason : storage_access_restored или storage_transport_restored}';

    protected $description = 'Возобновляет одну исчерпанную очистку с проверкой прав и неизменяемой квитанцией.';

    public function handle(RetryExhaustedDocumentCleanup $retry): int
    {
        try {
            $receipt = $retry->handle((int) $this->argument('organization'), (int) $this->argument('project'),
                (int) $this->argument('session'), (int) $this->argument('debt'), (string) $this->argument('identity'),
                (string) $this->argument('request'), (int) $this->argument('actor'), (string) $this->argument('reason'));
            $this->info('Повторная очистка зарегистрирована. Квитанция: '.$receipt);

            return self::SUCCESS;
        } catch (InvalidArgumentException) {
            $this->error('Очистка не возобновлена. Проверьте права, исходную идентичность, статус и лимит повторов.');

            return self::FAILURE;
        }
    }
}
