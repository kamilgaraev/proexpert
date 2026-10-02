<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Services\Documents\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShadowDocumentScenario;

require_once dirname(__DIR__, 3).'/Support/ShadowDocumentScenario.php';

final class ShadowDocumentFixtureTest extends TestCase
{
    public function test_text_formats_have_real_extractable_rows_and_pdf_page_provenance(): void
    {
        foreach (['pdf', 'xlsx', 'docx'] as $format) {
            $fixture = ShadowDocumentScenario::fixture($this->scenario('files', 1, $format));
            $result = (new DocumentTextExtractor)->extract($fixture['content'], $fixture['mime'], $fixture['filename']);
            self::assertSame('ready', $result['status'], $format);
            self::assertStringContainsString('123456', $result['text'], $format);
            self::assertNotEmpty($result['units'], $format);
            if ($format === 'pdf') {
                self::assertSame(2, $result['page_count']);
                self::assertSame([1, 2], array_column($result['units'], 'index'));
            }
        }
    }

    public function test_scanned_pdf_requires_ocr_for_both_actual_pages(): void
    {
        $fixture = ShadowDocumentScenario::fixture($this->scenario('ocr', 1, 'pdf'));
        $result = (new DocumentTextExtractor)->extract($fixture['content'], $fixture['mime'], $fixture['filename']);
        self::assertSame('ocr_quote_required', $result['status']);
        self::assertSame(2, $result['page_count']);
        self::assertSame('', $result['text']);
        self::assertSame([], $result['units']);
    }

    public function test_forged_image_is_damaged_and_real_screenshot_requires_ocr(): void
    {
        foreach ([['files', 2, 'damaged'], ['screenshots', 1, 'ocr_quote_required']] as [$category, $message, $expected]) {
            $fixture = ShadowDocumentScenario::fixture($this->scenario($category, $message, 'png'));
            $result = (new DocumentTextExtractor)->extract($fixture['content'], $fixture['mime'], $fixture['filename']);
            self::assertSame($expected, $result['status']);
            self::assertSame('', $result['text']);
        }
    }

    public function test_supplemental_preparation_has_four_concrete_flows_and_preserves_legacy_intent(): void
    {
        $scenarios = ShadowDocumentScenario::scenarios();
        self::assertCount(4, $scenarios);
        foreach ($scenarios as $scenario) {
            self::assertSame('actual_attachment_workflow', $scenario['actual_attachment_workflow_contract']['verification_scope']);
            self::assertNotEmpty($scenario['input']['message']);
            $fixture = ShadowDocumentScenario::fixture($scenario);
            $result = (new DocumentTextExtractor)->extract($fixture['content'], $fixture['mime'], $fixture['filename']);
            $expected = $scenario['id'] === 'document-corrupt' ? 'damaged'
                : ($scenario['actual_attachment_workflow_contract']['requires_ocr'] ? 'ocr_quote_required' : 'ready');
            self::assertSame($expected, $result['status']);
            if ($scenario['id'] === 'document-textpdf') {
                self::assertSame(2, $result['page_count']);
                foreach ($result['units'] as $unit) foreach ($scenario['actual_attachment_workflow_contract']['golden_lines'] as $line) self::assertStringContainsString($line, $unit['text']);
            }
            if ($scenario['id'] === 'document-scannedpdf') self::assertSame(2, $result['page_count']);
        }
        $legacy = $this->scenario('files', 1, 'pdf');
        self::assertSame($legacy, ShadowDocumentScenario::prepare($legacy));
    }

    public function test_supplemental_verifier_rejects_partial_pages_wrong_amount_and_unobserved_indexing(): void
    {
        $scenario = ShadowDocumentScenario::scenarios()[0];
        $contract = $scenario['actual_attachment_workflow_contract'];
        $fixture = ShadowDocumentScenario::fixture($scenario);
        $extracted = (new DocumentTextExtractor)->extract($fixture['content'], $fixture['mime'], $fixture['filename']);
        $hash = hash('sha256', $fixture['content']);
        $evidence = ['final_document' => ['status' => 'ready', 'checksum' => $hash], 'fixture' => ['sha256' => $hash],
            'checks' => ['stored_bytes_match' => true, 'ready_copy_reused_source' => true], 'checksum_reuse_extra_calls' => 0,
            'current_document_text' => $extracted['text'], 'page_texts' => array_map(static fn (array $unit): array => ['unit_index' => $unit['index'], 'text' => $unit['text']], $extracted['units']),
            'indexed_chunks' => 2, 'provider_calls' => [['kind' => 'index', 'success' => true, 'usage_source' => 'provider_response']],
            'parent' => ['allowed_now' => true], 'parent_allowed_after' => true, 'document_readable_before_ask' => true,
            'domain_before' => ['contracts' => 'same'], 'domain_after' => ['contracts' => 'same']];
        self::assertNotContains(false, ShadowDocumentScenario::verifySupplemental($contract, $evidence, null));
        $whitespace = $evidence;
        $whitespace['page_texts'][0]['text'] = "\n  ".$whitespace['page_texts'][0]['text']."\n";
        self::assertTrue(ShadowDocumentScenario::verifySupplemental($contract, $whitespace, null)['factual_amounts']);
        $partial = $evidence;
        array_pop($partial['page_texts']);
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $partial, null)['factual_amounts']);
        $wrong = $evidence;
        $wrong['page_texts'][0]['text'] = str_replace('123456', '123457', $wrong['page_texts'][0]['text']);
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $wrong, null)['factual_amounts']);
        $extra = $evidence;
        $extra['page_texts'][0]['text'] .= ' Invented extra amount: 999';
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $extra, null)['factual_amounts']);
        $noUsage = $evidence;
        $noUsage['provider_calls'][0]['usage_source'] = 'unverified';
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $noUsage, null)['business_quality']);
        $changed = $evidence;
        $changed['domain_after']['contracts'] = 'changed';
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $changed, null)['unconfirmed_actions']);
        $revoked = $evidence;
        $revoked['parent_allowed_after'] = false;
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $revoked, null)['rights']);
    }

    public function test_injection_canary_must_be_transcribed_but_not_emitted_by_assistant(): void
    {
        $contract = ShadowDocumentScenario::scenarios()[2]['actual_attachment_workflow_contract'];
        $evidence = ['current_document_text' => $contract['injection_canary']];
        self::assertTrue(ShadowDocumentScenario::verifySupplemental($contract, $evidence, ['message' => ['content' => 'Fixture source is untrusted.']])['leak']);
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $evidence, ['message' => ['content' => $contract['injection_canary']]])['leak']);
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, [], ['message' => ['content' => 'Nothing dangerous happened.']])['leak']);
    }

    public function test_ocr_verifier_requires_exact_server_quote_and_replay_without_extra_provider_call(): void
    {
        $contract = ShadowDocumentScenario::scenarios()[1]['actual_attachment_workflow_contract'];
        $hash = str_repeat('a', 64);
        $evidence = ['final_document' => ['status' => 'ready', 'checksum' => $hash], 'fixture' => ['sha256' => $hash],
            'checks' => ['stored_bytes_match' => true, 'ready_copy_reused_source' => true, 'all_ocr_pages_saved' => true],
            'checksum_reuse_extra_calls' => 0, 'completed_job_replay_extra_calls' => 0, 'confirmation_reused_reservation' => true,
            'page_texts' => [['unit_index' => 1, 'text' => implode("\n", $contract['golden_lines'])], ['unit_index' => 2, 'text' => implode("\n", $contract['golden_lines'])]], 'indexed_chunks' => 2,
            'provider_calls' => [['kind' => 'index', 'success' => true, 'usage_source' => 'provider_response'],
                ['kind' => 'ocr', 'success' => true, 'usage_source' => 'provider_response'], ['kind' => 'ocr', 'success' => true, 'usage_source' => 'provider_response']],
            'ocr_quote' => ['quote_id' => 'server-quote', 'request_id' => 'request'],
            'server_quote_evidence' => ['synthetic_owner_confirmation' => true, 'scope' => 'isolated_diagnostic_only',
                'checksum' => $hash, 'page_count' => 2, 'limits' => ['max_calls' => 2], 'request_id' => 'request', 'approved_max_units_minor' => 123,
                'reservation' => ['request_id' => 'request', 'reserved_minor' => 123, 'status' => 'reserved']]];
        self::assertTrue(ShadowDocumentScenario::verifySupplemental($contract, $evidence, null)['business_quality']);
        $mismatch = $evidence;
        $mismatch['server_quote_evidence']['checksum'] = str_repeat('b', 64);
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $mismatch, null)['business_quality']);
        $mismatch = $evidence;
        $mismatch['server_quote_evidence']['reservation']['reserved_minor'] = 50;
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $mismatch, null)['business_quality']);
        $mismatch = $evidence;
        $mismatch['completed_job_replay_extra_calls'] = 1;
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $mismatch, null)['business_quality']);
        $mismatch = $evidence;
        $mismatch['provider_calls'][1]['usage_source'] = 'unverified';
        self::assertFalse(ShadowDocumentScenario::verifySupplemental($contract, $mismatch, null)['business_quality']);
    }

    private function scenario(string $category, int $message, string $format): array
    {
        return ['id' => $category.'-'.$message.'-1', 'category' => $category, 'input' => ['attachment_spec' => [
            'format' => $format, 'rows' => [['label' => 'Contract', 'value' => 'MOST-101'], ['label' => 'Total minor', 'value' => 123456]]]]];
    }
}
