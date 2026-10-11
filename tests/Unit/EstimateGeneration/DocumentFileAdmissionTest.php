<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentFileAdmission;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentFileAdmissionTest extends TestCase
{
    public function test_a_pdf_renamed_as_cad_cannot_bypass_the_enabled_format_policy(): void
    {
        $this->expectExceptionMessage('document_signature_format_mismatch');
        (new DocumentFileAdmission)->signatureFormat('%PDF-1.7', 'dwg');
    }

    public function test_known_headers_are_matched_to_their_format_and_unknown_or_renamed_inputs_are_rejected(): void
    {
        $admission = new DocumentFileAdmission;
        foreach (['pdf' => '%PDF-1.7', 'jpg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1A\n",
            'xlsx' => "PK\x03\x04", 'xls' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 'dwg' => 'AC1032', 'dxf' => "0\r\nSECTION\r\n"] as $format => $header) {
            self::assertSame($format, $admission->signatureFormat($header, $format));
            try {
                $admission->signatureFormat($header, $format === 'pdf' ? 'dwg' : 'pdf');
                self::fail('Renamed format was accepted');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('document_signature_format_mismatch', $exception->getMessage());
            }
        }
        self::assertSame('jpg', $admission->signatureFormat("\xFF\xD8\xFF", 'jpeg'));
        $this->expectException(InvalidArgumentException::class);
        $admission->signatureFormat('not a supported document', 'dwg');
    }

    public function test_admission_matches_real_reader_limits_and_respects_stricter_parser_configuration(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
        try {
            $admission = new DocumentFileAdmission;
            self::assertSame(200 * 1024 * 1024, $admission->maximumBytes('pdf'));
            self::assertSame(50 * 1024 * 1024, $admission->maximumBytes('dwg'));
            self::assertSame(50 * 1024 * 1024, $admission->maximumBytes('xlsx'));
            self::assertSame(10 * 1024 * 1024, $admission->maximumBytes('png'));
            config(['estimate-generation.vision.cad_runtime.max_input_bytes' => 1024,
                'estimate-generation.vision.preprocessing.max_bytes' => 2048]);
            self::assertSame(1024, $admission->maximumBytes('dwg'));
            self::assertSame(2048, $admission->maximumBytes('png'));
        } finally {
            Container::setInstance($previous);
        }
    }
}
