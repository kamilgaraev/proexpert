<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantDomainSearchCursorTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_flat_search_schema_exposes_bounded_nullable_continuation(): void
    {
        $reader = (new ReflectionClass(AssistantDomainReadService::class))->newInstanceWithoutConstructor();
        $tool = new SearchAssistantDomainTool(new AssistantDomainCatalog(AssistantDomainCatalog::defaults()), $reader);
        $schema = $tool->getParametersSchema();

        self::assertSame(['string', 'null'], $schema['properties']['cursor']['type']);
        self::assertSame(2048, $schema['properties']['cursor']['maxLength']);
        self::assertContains('cursor', $schema['required']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function test_encrypted_cursor_binds_every_search_scope_component_without_exposing_ids_or_query(): void
    {
        $this->configureEncryption();
        $reflection = new ReflectionClass(AssistantDomainReadService::class);
        $reader = $reflection->newInstanceWithoutConstructor();
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $actor = new User;
        $actor->setAttribute('id', 483729);
        $arguments = [$catalog->definition('projects'), 'project', 'PRIVATE_QUERY_439587', 174329, 2, ['name', 'status'], $actor, 593174];
        $scope = $reflection->getMethod('searchCursorScope')->invokeArgs($reader, $arguments);
        foreach ([731924, '02dd627f-919f-4afb-a6f0-b9b01910ad82'] as $key) {
            $cursor = $reflection->getMethod('encodeSearchCursor')->invoke($reader, $key, $scope);
            self::assertLessThanOrEqual(2048, strlen($cursor));
            self::assertStringNotContainsString((string) $key, $cursor);
            self::assertStringNotContainsString('PRIVATE_QUERY_439587', $cursor);
            self::assertSame($key, $reflection->getMethod('decodeSearchCursor')->invoke($reader, $cursor, $scope));
        }
        $reordered = $arguments;
        $reordered[5] = ['status', 'name'];
        self::assertSame($scope, $reflection->getMethod('searchCursorScope')->invokeArgs($reader, $reordered));
        $otherActor = new User;
        $otherActor->setAttribute('id', 483730);
        foreach ([0 => $catalog->definition('contracts'), 1 => 'contract', 2 => 'other', 3 => 174330,
            4 => 3, 5 => ['name'], 6 => $otherActor, 7 => 593175] as $index => $value) {
            $changed = $arguments;
            $changed[$index] = $value;
            $changedScope = $reflection->getMethod('searchCursorScope')->invokeArgs($reader, $changed);
            self::assertNotSame($scope, $changedScope);
            try {
                $reflection->getMethod('decodeSearchCursor')->invoke($reader, $cursor, $changedScope);
                self::fail('A cursor cannot continue a different actor, organization or search.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('cursor', $exception->errors());
            }
        }
    }

    public function test_invalid_cursor_is_rejected_and_missing_cursor_starts_first_page(): void
    {
        $this->configureEncryption();
        $reflection = new ReflectionClass(AssistantDomainReadService::class);
        $reader = $reflection->newInstanceWithoutConstructor();
        $decode = $reflection->getMethod('decodeSearchCursor');
        self::assertNull($decode->invoke($reader, null, 'scope'));
        foreach (['', 'forged', [], 42, str_repeat('x', 2049),
            Crypt::encryptString('{"version":1,"after":0,"scope":"scope"}'),
            Crypt::encryptString('{"version":1,"after":{},"scope":"scope"}'),
            Crypt::encryptString('{"version":2,"after":1,"scope":"scope"}')] as $cursor) {
            try {
                $decode->invoke($reader, $cursor, 'scope');
                self::fail('Invalid continuation must fail closed.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('cursor', $exception->errors());
            }
        }
    }

    private function configureEncryption(): void
    {
        $application = Facade::getFacadeApplication();
        $application->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $application->instance('validator', new \Illuminate\Validation\Factory($application->make('translator'), $application));
    }
}
