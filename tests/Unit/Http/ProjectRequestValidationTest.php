<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Api\V1\Admin\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\Project\UpdateProjectRequest;
use PHPUnit\Framework\TestCase;
use Stringable;

class ProjectRequestValidationTest extends TestCase
{
    public function test_project_create_and_update_requests_accept_draft_status(): void
    {
        $this->assertStatusRuleContainsDraft((new StoreProjectRequest())->rules()['status']);
        $this->assertStatusRuleContainsDraft((new UpdateProjectRequest())->rules()['status']);
    }

    public function test_project_translation_file_contains_readable_validation_messages(): void
    {
        $messages = require dirname(__DIR__, 3) . '/lang/ru/project.php';

        self::assertSame('Проект успешно создан.', $messages['created']);
        self::assertSame('Проверьте корректность заполнения полей.', $messages['validation_failed']);
        self::assertSame('Название проекта обязательно для заполнения.', $messages['validation']['name_required']);
        self::assertSame('Выберите допустимый статус проекта.', $messages['validation']['status_invalid']);
    }

    private function assertStatusRuleContainsDraft(array $rules): void
    {
        $serializedRules = array_map(
            static fn (mixed $rule): string => is_string($rule) || $rule instanceof Stringable
                ? (string) $rule
                : get_debug_type($rule),
            $rules
        );

        self::assertContains('string', $serializedRules);
        self::assertStringContainsString('draft', implode('|', $serializedRules));
    }
}
