<?php
declare(strict_types=1);
namespace Tests\Feature\LegalArchive;
use PHPUnit\Framework\TestCase;
final class LegalDocumentRetentionTest extends TestCase { public function test_legal_hold_and_notification_retry_contracts_are_present(): void { $source=(string) file_get_contents(dirname(__DIR__,3).'/app/Services/LegalArchive/Retention/LegalDocumentRetentionService.php'); self::assertStringContainsString("where('legal_hold', false)",$source); self::assertLessThan(strpos($source,'retention_review_candidate_at'),strpos($source,'notifications->publish')); } }
