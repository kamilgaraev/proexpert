<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\FinanceInputValidation;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\PreviewEstimateFinanceRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceInputValidationTest extends TestCase
{
    public function test_validates_both_sides_of_8001_positions_and_strips_unvalidated_fields(): void
    {
        $input = $this->input(8001);
        $input['lines'][0]['organization_id'] = 999;
        $input['lines'][0]['condition_version'] = 7;
        $result = FinanceInputValidation::validate($input, SaveEstimateFinanceRequest::inputRules());
        self::assertCount(8001, $result['target_keys']);
        self::assertCount(16002, $result['lines']);
        self::assertArrayNotHasKey('organization_id', $result['lines'][0]);
        self::assertSame(7, $result['lines'][0]['condition_version']);
        self::assertSame($input['lines'][16001], $result['lines'][16001]);
    }

    public function test_rejects_invalid_trailing_line_and_case_variant_duplicate_uuid(): void
    {
        $input = $this->input(3);
        $input['lines'][5]['quantity'] = '-1';
        $validator = FinanceInputValidation::make($input, SaveEstimateFinanceRequest::inputRules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('lines.5.quantity'));
        $input['lines'][5]['quantity'] = '1';
        $input['lines'][5]['key'] = strtoupper($input['lines'][0]['key']);
        $validator = FinanceInputValidation::make($input, SaveEstimateFinanceRequest::inputRules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('lines.5.key'));
    }

    public function test_form_request_and_source_preview_use_same_validation(): void
    {
        $input = $this->input(2);
        $input['lines'][0]['unexpected'] = 'discard';
        $request = SaveEstimateFinanceRequest::create('/', 'POST', $input);
        $request->setValidator($request->validator());
        self::assertSame(FinanceInputValidation::validate($input, $request->rules()), $request->validated());
        self::assertSame($request->validated(), $request->safe()->all());
        self::assertSame(['lines' => $request->validated('lines')], $request->safe(['lines']));
        $preview = PreviewEstimateFinanceRequest::create('/', 'POST', ['preview_operation' => 'source_amount', 'item_ids' => range(1, 8001)]);
        $preview->setValidator($preview->validator());
        self::assertCount(8001, $preview->validated('item_ids'));
        $validator = FinanceInputValidation::make(['preview_operation' => 'source_amount', 'item_ids' => [1, '1']], PreviewEstimateFinanceRequest::sourceRules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('item_ids.1'));
    }

    public function test_rejects_malformed_rows_and_oversized_payload(): void
    {
        $input = $this->input(1);
        $input['lines'][0] = 'invalid';
        $validator = FinanceInputValidation::make($input, SaveEstimateFinanceRequest::inputRules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('lines.0'));
        $input['target_keys'] = array_fill(0, 20001, 'i:1');
        $validator = FinanceInputValidation::make($input, SaveEstimateFinanceRequest::inputRules());
        self::assertTrue($validator->fails());
        self::assertTrue($validator->errors()->has('target_keys'));
    }

    private function input(int $count): array
    {
        $input = ['revision' => 0, 'mutation_id' => (string) Str::uuid(), 'target_keys' => [], 'lines' => []];
        for ($id = 1; $id <= $count; $id++) {
            $input['target_keys'][] = 'i:'.$id;
            foreach ([1, 2] as $contract) {
                $input['lines'][] = ['key' => (string) Str::uuid(), 'target_key' => 'i:'.$id,
                    'source' => 'contract', 'contract_id' => $contract, 'currency' => 'RUB', 'quantity' => '1',
                    'unit_price' => '100', 'vat_rate' => '20', 'vat_mode' => 'exclusive',
                    'price_basis' => 'without_vat', 'method' => 'unit', 'composition_confirmed' => true];
            }
        }

        return $input;
    }
}
