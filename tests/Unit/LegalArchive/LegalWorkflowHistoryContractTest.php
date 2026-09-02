<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalWorkflowHistoryRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalWorkflowHistoryResource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowHistoryContractTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $this->translator = new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru');
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('translator', $this->translator);
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('request', Request::create('/'));
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_resource_preserves_comments_and_human_labels_without_exposing_internal_context(): void
    {
        $resource = new LegalWorkflowHistoryResource((object) [
            'id' => 5, 'action' => 'return', 'actor_name' => 'Анна Петрова', 'actor_type' => 'user',
            'step_label' => 'Проверка условий', 'version_number' => '1.0',
            'comment' => "Уточнить срок.\nДобавить дату.", 'reason' => 'Нужна новая редакция',
            'decided_at' => '2026-09-02 10:00:00+00', 'document_content_hash' => 'internal',
            'context' => ['internal' => true], 'request_hash' => 'internal',
        ]);

        self::assertSame([
            'id' => 5, 'action' => 'return', 'action_label' => 'Возвращено на доработку',
            'actor_name' => 'Анна Петрова', 'step_label' => 'Проверка условий', 'version_number' => '1.0',
            'comment' => "Уточнить срок.\nДобавить дату.", 'reason' => 'Нужна новая редакция',
            'decided_at' => '2026-09-02T10:00:00+00:00',
        ], $resource->resolve(Request::create('/')));
    }

    public function test_system_decision_and_unknown_action_have_readable_labels(): void
    {
        $resource = new LegalWorkflowHistoryResource((object) [
            'id' => 1, 'action' => 'unexpected_action', 'actor_name' => null, 'actor_type' => 'system',
            'step_label' => null, 'version_number' => null, 'comment' => null, 'reason' => null,
            'decided_at' => '2026-09-02 10:00:00+00',
        ]);
        $result = $resource->resolve(Request::create('/'));
        self::assertSame('Решение по согласованию', $result['action_label']);
        self::assertSame('Система', $result['actor_name']);
        self::assertNull($result['version_number']);
    }

    public function test_cursor_validation_rejects_invalid_values_and_request_requires_authentication(): void
    {
        $request = new LegalWorkflowHistoryRequest;
        $request->setUserResolver(static fn () => null);
        self::assertFalse($request->authorize());
        $validator = new Factory($this->translator);
        foreach ([[], ['before_id' => null], ['before_id' => '21']] as $input) {
            self::assertTrue($validator->make($input, $request->rules())->passes());
        }
        foreach ([['before_id' => -1], ['before_id' => 0], ['before_id' => 'abc'], ['before_id' => '1.5']] as $input) {
            self::assertTrue($validator->make($input, $request->rules())->fails());
        }
    }
}
