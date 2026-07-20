<?php
declare(strict_types=1);
namespace Tests\Feature\LegalArchive;
use PHPUnit\Framework\TestCase;
final class LegalDocumentObligationTest extends TestCase { public function test_effective_document_sync_contract_is_declared(): void { $source=(string) file_get_contents(dirname(__DIR__,3).'/app/Services/LegalArchive/Obligations/LegalDocumentObligationService.php'); self::assertStringContainsString('syncFromEffectiveDocument',$source); self::assertStringContainsString('updateOrCreate',$source); } }
