<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Organization;
use App\Models\PersonalFile;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PersonalFileListProfileTest extends TestCase
{
    public function test_folder_profile_preserves_scope_payload_and_url_opt_out_without_extra_storage_calls(): void
    {
        $context = AdminApiTestContext::create();
        $own = $this->file((int) $context->organization->id, (int) $context->user->id);
        $folder = $this->file((int) $context->organization->id, (int) $context->user->id, [
            'is_folder' => true, 'storage_key' => null, 'original_name' => 'assistant',
        ]);
        $this->file((int) Organization::factory()->create()->id, (int) $context->user->id);
        $this->file((int) $context->organization->id, (int) User::factory()->create()->id);
        $url = 'https://storage.example.test/PRIVATE_FILE?signature=PRIVATE_SIGNATURE';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryDownloadUrl')->once()->with($own->storage_key, Mockery::type('int'), $own->original_name)->andReturn($url);
        $this->app->instance(FileService::class, $files);
        $profiles = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$profiles): void {
            if ($event->message === 'api_response_timing' && ($event->context['route'] ?? null) === 'api/v1/admin/personal-files') {
                $profiles[] = $event->context;
            }
        });

        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files?folder=reports&per_page=10');
        $response->assertOk()->assertJsonPath('meta.total', 2);
        self::assertEqualsCanonicalizing([$own->id, $folder->id], array_column($response->json('data'), 'id'));
        $rows = collect($response->json('data'))->keyBy('id');
        self::assertSame($url, $rows[$own->id]['download_url']);
        self::assertNull($rows[$folder->id]['download_url']);
        self::assertArrayNotHasKey('storage_key', $rows[$own->id]);
        self::assertCount(1, $profiles);
        $this->assertPhases($profiles[0], true);
        self::assertSame(2, $profiles[0]['processing_phases']['file_personal_list']['sql_count']);

        $withoutUrls = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files?folder=reports&per_page=10&include_download_urls=0');
        $withoutUrls->assertOk()->assertJsonPath('meta.total', 2);
        $expected = array_map(static function (array $row): array {
            $row['download_url'] = null;

            return $row;
        }, $response->json('data'));
        self::assertSame($expected, $withoutUrls->json('data'));
        self::assertSame($response->json('meta'), $withoutUrls->json('meta'));
        self::assertCount(2, $profiles);
        $this->assertPhases($profiles[1], false);
        self::assertStringNotContainsString('PRIVATE_', json_encode($profiles, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('storage.example.test', json_encode($profiles, JSON_THROW_ON_ERROR));
    }

    public function test_url_failure_remains_a_null_url_and_empty_folder_is_still_profiled(): void
    {
        $context = AdminApiTestContext::create();
        $own = $this->file((int) $context->organization->id, (int) $context->user->id);
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('temporaryDownloadUrl')->once()->andThrow(new \RuntimeException('PRIVATE_STORAGE_FAILURE'));
        $this->app->instance(FileService::class, $files);
        $profiles = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$profiles): void {
            if ($event->message === 'api_response_timing' && ($event->context['route'] ?? null) === 'api/v1/admin/personal-files') {
                $profiles[] = $event->context;
            }
        });
        $response = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files?folder=reports');
        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.download_url', null);
        self::assertCount(1, $profiles);
        $this->assertPhases($profiles[0], true);
        $empty = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/personal-files?folder=missing');
        $empty->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        self::assertCount(2, $profiles);
        $this->assertPhases($profiles[1], false);
        self::assertStringNotContainsString('PRIVATE_', json_encode($profiles, JSON_THROW_ON_ERROR));
    }

    private function file(int $organization, int $user, array $attributes = []): PersonalFile
    {
        return PersonalFile::query()->create($attributes + [
            'organization_id' => $organization, 'user_id' => $user,
            'storage_key' => 'org-'.$organization.'/personal-files/user-'.$user.'/PRIVATE_FILE.pdf',
            'directory' => 'reports', 'original_name' => 'PRIVATE_FILE.pdf',
            'mime_type' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'size' => 10, 'is_folder' => false,
        ]);
    }

    private function assertPhases(array $profile, bool $url): void
    {
        $phases = $profile['processing_phases'];
        foreach (['file_personal_list', 'file_personal_payload'] as $phase) {
            self::assertArrayHasKey($phase, $phases);
            self::assertSame(1, $phases[$phase]['count']);
        }
        if ($url) {
            self::assertArrayHasKey('file_personal_url', $phases);
            self::assertSame(1, $phases['file_personal_url']['count']);
        } else {
            self::assertArrayNotHasKey('file_personal_url', $phases);
        }
        foreach (array_intersect_key($phases, array_flip(['file_personal_list', 'file_personal_payload', 'file_personal_url'])) as $phase) {
            foreach ($phase as $value) {
                self::assertTrue(is_int($value) || is_float($value) || $value === null);
            }
            self::assertGreaterThanOrEqual(0, $phase['total_ms']);
            self::assertGreaterThanOrEqual(0, $phase['sql_count']);
        }
    }
}
