<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveTypeProfileRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveTypeProfileRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalArchiveTypeProfileConfidentialityRequestTest extends TestCase
{
    #[DataProvider('levels')]
    public function test_creation_and_update_validate_supported_confidentiality_values(mixed $value, bool $valid): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));

        foreach ([new StoreLegalArchiveTypeProfileRequest(), new UpdateLegalArchiveTypeProfileRequest()] as $request) {
            $validator = $factory->make(
                ['confidentiality_level' => $value],
                ['confidentiality_level' => $request->rules()['confidentiality_level']],
            );

            self::assertSame($valid, $validator->passes(), $request::class);
        }
    }

    public static function levels(): iterable
    {
        yield 'inherit base' => [null, true];
        yield 'public' => ['public', true];
        yield 'internal' => ['internal', true];
        yield 'restricted' => ['restricted', true];
        yield 'secret' => ['secret', true];
        yield 'human label is not a machine value' => ['Конфиденциальный', false];
        yield 'misspelled value' => ['restriced', false];
        yield 'arbitrary classification' => ['custom', false];
        yield 'over database length' => [str_repeat('a', 33), false];
        yield 'array' => [['restricted'], false];
        yield 'number' => [1, false];
    }
}
