<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JournalEntryFormRequestContractTest extends TestCase
{
    #[Test]
    public function both_interfaces_use_the_same_typed_entry_requests(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            '/app/Http/Controllers/Api/ConstructionJournalEntryController.php',
            '/app/Http/Controllers/Api/V1/Mobile/ConstructionJournalEntryController.php',
        ] as $path) {
            $source = file_get_contents($root.$path);
            self::assertStringContainsString('StoreJournalEntryRequest $request', $source);
            self::assertStringContainsString('UpdateJournalEntryRequest $request', $source);
            self::assertStringContainsString('ApproveJournalEntryRequest $request', $source);
            self::assertStringContainsString('RejectJournalEntryRequest $request', $source);
            self::assertStringNotContainsString('$request->validate(', $source);
        }
    }

    #[Test]
    public function form_requests_return_interface_specific_standard_responses(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3)
            .'/app/Http/Requests/ConstructionJournal/ConstructionJournalFormRequest.php');

        self::assertStringContainsString('failedValidation', $source);
        self::assertStringContainsString('MobileResponse::error', $source);
        self::assertStringContainsString('AdminResponse::error', $source);
    }
}
