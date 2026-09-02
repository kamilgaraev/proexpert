<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveTypeProfileRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveRetentionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveTypeProfileRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalArchiveRetentionPolicyRequestTest extends TestCase
{
    public function test_validation_errors_use_readable_retention_field_names(): void
    {
        $factory = new Factory(new Translator(new FileLoader(new Filesystem(), dirname(__DIR__, 3).'/lang'), 'ru'));
        $validator = $factory->make([
            'lock_version' => 1,
            'retention_policy' => str_repeat('Я', 129),
            'retention_basis' => str_repeat('Я', 1001),
            'retention_started_at' => 'invalid',
            'retention_until' => 'invalid',
        ], (new UpdateLegalArchiveRetentionRequest())->rules());

        foreach (['retention_policy' => 'правило хранения', 'retention_basis' => 'основание хранения', 'retention_started_at' => 'начало хранения', 'retention_until' => 'хранить до'] as $field => $label) {
            self::assertStringContainsString($label, $validator->errors()->first($field));
            self::assertStringNotContainsString($field, $validator->errors()->first($field));
        }
    }

    #[DataProvider('policies')]
    public function test_policy_validation_matches_storage_capacity(FormRequest $request, mixed $value, bool $valid): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $validator = $factory->make(
            ['retention_policy' => $value],
            ['retention_policy' => $request->rules()['retention_policy']],
        );

        self::assertSame($valid, $validator->passes());
    }

    public static function policies(): iterable
    {
        foreach ([
            'create type' => new StoreLegalArchiveTypeProfileRequest(),
            'update type' => new UpdateLegalArchiveTypeProfileRequest(),
            'document retention' => new UpdateLegalArchiveRetentionRequest(),
        ] as $operation => $request) {
            yield "$operation: 128 ASCII characters" => [$request, str_repeat('a', 128), true];
            yield "$operation: 128 Cyrillic characters" => [$request, str_repeat('Я', 128), true];
            yield "$operation: 129 ASCII characters" => [$request, str_repeat('a', 129), false];
            yield "$operation: 129 Cyrillic characters" => [$request, str_repeat('Я', 129), false];
            yield "$operation: array" => [$request, ['five years'], false];
            yield "$operation: null" => [$request, null, !($request instanceof UpdateLegalArchiveRetentionRequest)];
        }
    }

    #[DataProvider('dates')]
    public function test_retention_date_contract(?string $start, ?string $until, bool $valid): void
    {
        $factory = new Factory(new Translator(new ArrayLoader(), 'ru'));
        $rules = (new UpdateLegalArchiveRetentionRequest())->rules();
        $validator = $factory->make(
            ['retention_started_at' => $start, 'retention_until' => $until],
            ['retention_started_at' => $rules['retention_started_at'], 'retention_until' => $rules['retention_until']],
        );

        self::assertSame($valid, $validator->passes());
    }

    public static function dates(): iterable
    {
        yield 'dates not assigned' => [null, null, true];
        yield 'only start assigned' => ['2026-09-03', null, true];
        yield 'same day' => ['2026-09-03', '2026-09-03', true];
        yield 'ordered dates' => ['2026-09-03', '2031-09-03', true];
        yield 'end before start' => ['2031-09-03', '2026-09-03', false];
        yield 'only end assigned' => [null, '2031-09-03', true];
    }
}
