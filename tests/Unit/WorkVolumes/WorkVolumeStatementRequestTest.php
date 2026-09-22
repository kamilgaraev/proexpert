<?php

declare(strict_types=1);

namespace Tests\Unit\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementImportPreviewRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeAcceptanceMappingRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementReviewRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class WorkVolumeStatementRequestTest extends TestCase
{
    public function test_write_request_rejects_values_that_cannot_be_stored_exactly(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $line = ['line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена', 'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1']];
        foreach ([['name' => str_repeat('Я', 256)], ['quantity' => '1000000000000000000'], ['quantity' => 100]] as $change) {
            self::assertTrue($factory->make(['lines' => [[...$line, ...$change]]], (new WorkVolumeStatementRequest())->rules())->fails(), implode(',', array_keys($change)));
        }
        foreach (['0', '0.000001', '999999999999999999.999999'] as $quantity) {
            self::assertFalse($factory->make(['lines' => [[...$line, 'quantity' => $quantity]]], (new WorkVolumeStatementRequest())->rules())->fails());
        }
    }

    public function test_preview_rejects_malformed_rows_before_string_normalization(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        foreach (['not-a-row', ['quantity' => ['wrong']], ['name' => ['wrong']], ['unit_code' => ['wrong']]] as $row) {
            self::assertTrue($factory->make(['rows' => [$row]], (new WorkVolumeStatementImportPreviewRequest())->rules())->fails());
        }
        self::assertFalse($factory->make(['rows' => [['quantity' => 'неразборчиво']]], (new WorkVolumeStatementImportPreviewRequest())->rules())->fails());
    }

    public function test_accepted_mapping_requires_exact_quantities_and_explicit_revision(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $rules = (new WorkVolumeAcceptanceMappingRequest())->rules();
        $payload = ['operation_key' => 'mapping', 'expected_revision' => 0, 'reason' => 'Обмер', 'allocations' => []];
        self::assertFalse($factory->make($payload, $rules)->fails());
        foreach ([['expected_revision' => null], ['expected_revision' => -1], ['reason' => ''], ['allocations' => null]] as $change) {
            self::assertTrue($factory->make([...$payload, ...$change], $rules)->fails());
        }
        foreach ([80, '80.0000001', '1000000000000000000', ['80']] as $quantity) {
            self::assertTrue($factory->make([...$payload, 'allocations' => [['statement_line_id' => 1, 'quantity' => $quantity]]], $rules)->fails());
        }
        self::assertTrue($factory->make([...$payload, 'allocations' => [
            ['statement_line_id' => 1, 'quantity' => '40'], ['statement_line_id' => 1, 'quantity' => '40'],
        ]], $rules)->fails());
        self::assertFalse($factory->make([...$payload, 'allocations' => [['statement_line_id' => 1, 'quantity' => '80.000001']]], $rules)->fails());
    }

    public function test_review_request_requires_expected_round(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $rules = (new WorkVolumeStatementReviewRequest())->rules();
        foreach ([[], ['expected_review_round' => -1], ['expected_review_round' => 'wrong'], ['expected_review_round' => 0, 'reason' => ['wrong']]] as $payload) {
            self::assertTrue($factory->make($payload, $rules)->fails());
        }
        self::assertFalse($factory->make(['expected_review_round' => 0], $rules)->fails());
        self::assertFalse($factory->make(['expected_review_round' => 1, 'reason' => 'Уточнить объём'], $rules)->fails());
    }

    public function test_client_cannot_forge_import_provenance(): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $payload = ['lines' => [[
            'line_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'Стена',
            'unit_code' => 'м²', 'quantity' => '100', 'place' => ['axis' => 'А-1'],
        ]]];
        foreach ([['source_file_path' => 'org-999/private.xlsx'], ['source_file_hash' => str_repeat('a', 64)], ['source_import_id' => 999]] as $forgery) {
            self::assertTrue($factory->make([...$payload, ...$forgery], (new WorkVolumeStatementRequest())->rules())->fails());
        }
    }
}
