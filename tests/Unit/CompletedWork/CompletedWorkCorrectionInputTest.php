<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork;

use App\Http\Requests\Api\V1\Admin\CompletedWork\CorrectCompletedWorkRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletedWorkCorrectionInputTest extends TestCase
{
    #[DataProvider('inputs')]
    public function test_correction_input_matches_database_precision_and_event_identity(array $overrides, bool $valid): void
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $validator = $factory->make(array_replace([
            'operation_key' => 'correction-input',
            'expected_version' => str_repeat('a', 64),
            'reason' => 'Исправление объёма по результатам обмера',
            'quantity' => '8.0001',
        ], $overrides), (new CorrectCompletedWorkRequest)->rules());

        self::assertSame($valid, $validator->passes());
    }

    public static function inputs(): array
    {
        return [
            'valid precise volume' => [[], true],
            'numeric event identity' => [['source_event_id' => 15], true],
            'invalid event text' => [['source_event_id' => 'abc'], false],
            'invalid zero identity' => [['source_event_id' => '0'], false],
            'excess precision' => [['quantity' => '8.00001'], false],
            'overflow quantity' => [['quantity' => '100000000000000'], false],
        ];
    }
}
