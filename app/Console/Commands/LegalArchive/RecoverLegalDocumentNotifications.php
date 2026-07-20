<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\LegalDocumentNotificationRecoveryService;
use Illuminate\Console\Command;

final class RecoverLegalDocumentNotifications extends Command
{
    protected $signature = 'legal-archive:recover-notification-deliveries {--limit=100}';
    protected $description = 'Возвращает зависшие доставки уведомлений юридического архива в повторяемое состояние';

    public function handle(LegalDocumentNotificationRecoveryService $recovery): int
    {
        $count = $recovery->recoverExpired((int) $this->option('limit'));
        $this->info((string) $count);

        return self::SUCCESS;
    }
}
