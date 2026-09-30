<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\DocumentTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssistantLegalNativeFileMetadataTest extends TestCase
{
    public function test_three_native_types_have_finite_safe_paths_and_current_version_fingerprints(): void
    {
        self::assertCount(3,Metadata::definitions());
        $uuid = '12345678-1234-1234-1234-123456789abc.pdf';
        foreach (Metadata::types() as $type) {
            $record = ['id'=>4,'organization_id'=>7,'document_id'=>3,'document_file_id'=>2,'parent_project_id'=>5,'parent_set_id'=>6,'project_id'=>5,
                'processing_status'=>'ready','size_bytes'=>12,'content_hash'=>str_repeat('a',64),'file_hash'=>str_repeat('a',64),'is_current'=>true,'updated_at'=>'2026-09-29 13:00:00+00'];
            $pathKey = Metadata::definitions()[$type]['path'];
            $record[$pathKey] = Metadata::prefix($type,$record).$uuid;
            Metadata::assertSource($type,$record);
            self::assertSame('application/pdf',Metadata::mime($type,$record));
            self::assertSame($uuid,Metadata::filename($type,$record));
            $version = Metadata::versionData($type,$record);
            self::assertNotSame(Metadata::fingerprint($version),Metadata::fingerprint(Metadata::versionData($type,array_replace($record,['updated_at'=>'2026-09-30 13:00:00+00']))));
            if ($type === 'legal_document_version') { self::assertSame('true',$version['is_current']); }
            foreach (['https://foreign.example/private.pdf','/etc/private.pdf','org-7/../private.pdf',Metadata::prefix($type,$record).$uuid.'?token=secret',
                str_replace('org-7/','org-8/',$record[$pathKey]),Metadata::prefix($type,$record).'../'.$uuid] as $bad) {
                try { Metadata::assertSource($type,array_replace($record,[$pathKey=>$bad])); self::fail('Unsafe persisted file identity.'); }
                catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_native_source_invalid',$exception->getMessage()); }
            }
            self::assertNotContains('metadata',array_keys(Metadata::versionExpressions($type)));
            self::assertNotContains('signature_file_url',array_keys(Metadata::versionExpressions($type)));
        }
    }

    public function test_real_pdf_text_and_exact_financial_value_are_extracted_as_document_data(): void
    {
        $text = 'Invoice 1234.56';
        $stream = "BT /F1 12 Tf 72 720 Td (".$text.") Tj ET";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $index=>$object) { $offsets[] = strlen($pdf); $pdf .= ($index+1)." 0 obj\n".$object."\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets,1) as $offset) { $pdf .= sprintf('%010d 00000 n ',$offset)."\n"; }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        $result = (new DocumentTextExtractor)->extract($pdf,'application/pdf','source.pdf');
        self::assertSame('ready',$result['status']);
        self::assertSame('full',$result['coverage']);
        self::assertStringContainsString($text,$result['text']);
        self::assertSame('pdf_text_layer',$result['units'][0]['provenance']['kind']);
    }
}
