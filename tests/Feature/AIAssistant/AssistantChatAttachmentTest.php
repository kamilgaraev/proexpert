<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\ChatAttachment;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRetentionService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditQuote;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class AssistantChatAttachmentTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $viewer;

    private ConversationManager $conversations;

    private FileService $files;

    private AssistantChatAttachmentService $attachments;

    private string $storedBytes = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = $this->member();
        $this->viewer = $this->member();

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $projects = Mockery::mock(UserProjectAccessService::class);
        $projects->shouldReceive('canAccessProject')->andReturn(true);
        $projects->shouldReceive('queryAccessibleProjects')->andReturnUsing(fn () => \App\Models\Project::query());
        $modules = Mockery::mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([['slug' => 'ai-assistant']]));
        $this->conversations = new ConversationManager(new AssistantDataAccessPolicy($authorization, $projects, $modules));
        $this->app->instance(ConversationManager::class, $this->conversations);

        $permissions = Mockery::mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturn(true);
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $this->files = Mockery::mock(FileService::class);
        $this->app->instance(FileService::class, $this->files);
        $this->attachments = new AssistantChatAttachmentService($this->files, $this->conversations, $permissions);
    }

    public function test_upload_returns_only_safe_metadata_and_content_is_private_to_owner(): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(function (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile {
            self::assertStringStartsWith('org-'.$this->organization->id.'/ai-assistant/chat-images/', $key);
            self::assertSame(hash('sha256', $bytes), $checksum);
            $this->storedBytes = $bytes;
            return new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime);
        });
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('evidence.png', 32, 24), $this->owner, (int) $this->organization->id);
        $metadata = $attachment->publicMetadata();

        self::assertSame(['id', 'name', 'mime', 'size', 'width', 'height'], array_keys($metadata));
        self::assertSame('image/png', $metadata['mime']);
        self::assertSame(32, $metadata['width']);
        self::assertSame(24, $metadata['height']);
        self::assertArrayNotHasKey('storage_path', $metadata);
        self::assertArrayNotHasKey('checksum', $metadata);
        self::assertArrayNotHasKey('organization_id', $metadata);
        self::assertArrayNotHasKey('user_id', $metadata);

        $this->files->shouldReceive('readCurrentBounded')->once()->andReturnUsing(function (string $key) use ($attachment) {
            self::assertSame($attachment->storage_path, $key);
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $this->storedBytes);
            rewind($stream);
            return $stream;
        });
        [$bytes, $mime] = $this->attachments->content($attachment->public_id, $this->owner, (int) $this->organization->id);
        self::assertSame($this->storedBytes, $bytes);
        self::assertSame('image/png', $mime);
    }

    public function test_upload_accepts_and_canonicalizes_real_jpeg_from_gd(): void
    {
        $this->assertCanonicalUpload(UploadedFile::fake()->image('camera.jpg', 30, 18), 'image/jpeg');
    }

    public function test_upload_accepts_and_canonicalizes_webp_when_gd_supports_it(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD WebP encoder is unavailable.');
        }
        $this->assertCanonicalUpload(UploadedFile::fake()->image('camera.webp', 30, 18), 'image/webp');
    }

    public function test_upload_rejects_foreign_organization(): void
    {
        $otherOrganization = Organization::factory()->create();
        $this->expectException(AuthorizationException::class);
        $this->attachments->upload(UploadedFile::fake()->image('image.png'), $this->owner, (int) $otherOrganization->id);
    }

    public function test_unlinked_orphan_can_only_be_used_by_its_uploader(): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('viewer.png', 10, 10), $this->viewer, (int) $this->organization->id);

        try {
            $this->attachments->prepareRequest(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'attachment_ids' => [$attachment->public_id]], $this->owner, (int) $this->organization->id);
            self::fail('A different organization member must not use another uploader’s orphan.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('ai_chat_attachments', ['id' => $attachment->id, 'message_id' => null]);
        }
        $this->expectException(AuthorizationException::class);
        $this->attachments->content($attachment->public_id, $this->owner, (int) $this->organization->id);
    }

    public function test_viewer_cannot_upload_to_or_prepare_an_attachment_for_a_shared_conversation(): void
    {
        $conversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('owner.png', 12, 12), $this->owner, (int) $this->organization->id, (int) $conversation->id);
        $this->conversations->updateParticipants($conversation, $this->owner, (int) $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'viewer']]);

        try {
            $this->attachments->upload(UploadedFile::fake()->image('viewer.png', 12, 12), $this->viewer, (int) $this->organization->id, (int) $conversation->id);
            self::fail('A viewer must not upload into a shared conversation.');
        } catch (AuthorizationException) {
            self::assertDatabaseCount('ai_chat_attachments', 1);
        }
        $this->expectException(AuthorizationException::class);
        $this->attachments->prepareRequest([
            'request_id' => (string) \Illuminate\Support\Str::uuid(), 'conversation_id' => $conversation->id,
            'attachment_ids' => [$attachment->public_id],
        ], $this->viewer, (int) $this->organization->id);
    }

    public function test_upload_rejects_invalid_type_byte_limit_and_pixel_limit(): void
    {
        foreach ([
            UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
            UploadedFile::fake()->create('large.png', 5121, 'image/png'),
            UploadedFile::fake()->image('wide.png', 2048, 2049),
        ] as $image) {
            try {
                $this->attachments->upload($image, $this->owner, (int) $this->organization->id);
                self::fail('Invalid image should be rejected.');
            } catch (ValidationException) {
                self::assertSame(0, ChatAttachment::query()->count());
            }
        }
    }

    public function test_upload_rejects_bad_png_crc_trailing_polyglot_svg_and_gif(): void
    {
        $sourceImage = UploadedFile::fake()->image('source.png', 8, 8);
        $validPng = file_get_contents($sourceImage->getPathname());
        self::assertIsString($validPng);
        $badCrc = substr($validPng, 0, -1).chr(ord($validPng[strlen($validPng) - 1]) ^ 1);

        foreach ([
            ['broken.png', $badCrc],
            ['polyglot.png', $validPng."\0trailing-data"],
            ['active.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['animated.gif', 'GIF89a'.str_repeat("\0", 64)],
        ] as [$filename, $bytes]) {
            try {
                $this->attachments->upload(UploadedFile::fake()->createWithContent($filename, $bytes), $this->owner, (int) $this->organization->id);
                self::fail('Malformed, active, or unsupported image must be rejected.');
            } catch (ValidationException) {
                self::assertDatabaseCount('ai_chat_attachments', 0);
            }
        }
    }

    public function test_request_binding_is_immutable_and_idempotent_for_same_request(): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(function (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile {
            $this->storedBytes = $bytes;
            return new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime);
        });
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('safe.png', 16, 16), $this->owner, (int) $this->organization->id);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $request = ['request_id' => $requestId, 'attachment_ids' => [$attachment->public_id]];

        $first = $this->attachments->prepareRequest($request, $this->owner, (int) $this->organization->id, true);
        $replay = $this->attachments->prepareRequest($request, $this->owner, (int) $this->organization->id, true);
        self::assertSame($first, $replay);
        self::assertSame($requestId, ChatAttachment::query()->findOrFail($attachment->id)->request_id);
        self::assertSame(['id', 'checksum', 'mime', 'size', 'width', 'height'], array_keys($first['attachment_manifest'][0]));

        try {
            $this->attachments->prepareRequest(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'attachment_ids' => [$attachment->public_id]], $this->owner, (int) $this->organization->id, true);
            self::fail('A bound image must not be rebound to another request.');
        } catch (ValidationException) {
            self::assertSame($requestId, ChatAttachment::query()->findOrFail($attachment->id)->request_id);
        }
    }

    public function test_bound_attachment_cannot_be_replayed_into_another_conversation(): void
    {
        $firstConversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $secondConversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('first.png', 14, 14), $this->owner, (int) $this->organization->id, (int) $firstConversation->id);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->attachments->prepareRequest(['request_id' => $requestId, 'conversation_id' => $firstConversation->id, 'attachment_ids' => [$attachment->public_id]], $this->owner, (int) $this->organization->id, true);

        $this->expectException(ValidationException::class);
        $this->attachments->prepareRequest(['request_id' => $requestId, 'conversation_id' => $secondConversation->id, 'attachment_ids' => [$attachment->public_id]], $this->owner, (int) $this->organization->id);
    }

    public function test_stale_orphan_is_rejected_for_request_and_content(): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('stale.png', 14, 14), $this->owner, (int) $this->organization->id);
        ChatAttachment::query()->whereKey($attachment->id)->update(['created_at' => now()->subDay()->subSecond()]);
        $attachment->refresh();

        try {
            $this->attachments->prepareRequest(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'attachment_ids' => [$attachment->public_id]], $this->owner, (int) $this->organization->id);
            self::fail('An expired orphan must not be bindable.');
        } catch (ValidationException) {
            self::assertDatabaseHas('ai_chat_attachments', ['id' => $attachment->id]);
        }
        $this->expectException(AuthorizationException::class);
        $this->attachments->content($attachment->public_id, $this->owner, (int) $this->organization->id);
    }

    public function test_content_rejects_a_checksum_mismatch(): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(function (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile {
            $this->storedBytes = $bytes;
            return new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime);
        });
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('integrity.png', 12, 12), $this->owner, (int) $this->organization->id);
        $corrupted = $this->storedBytes;
        $corrupted[10] = chr(ord($corrupted[10]) ^ 1);
        $this->files->shouldReceive('readCurrentBounded')->once()->andReturnUsing(function () use ($corrupted) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $corrupted);
            rewind($stream);
            return $stream;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assistant_attachment_integrity_failed');
        $this->attachments->content($attachment->public_id, $this->owner, (int) $this->organization->id);
    }

    public function test_linked_image_can_be_read_by_shared_member_until_membership_is_revoked(): void
    {
        $conversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(function (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile {
            $this->storedBytes = $bytes;
            return new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime);
        });
        $attachment = $this->attachments->upload(UploadedFile::fake()->image('photo.png', 20, 12), $this->owner, (int) $this->organization->id, (int) $conversation->id);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->attachments->prepareRequest([
            'request_id' => $requestId,
            'conversation_id' => $conversation->id,
            'attachment_ids' => [$attachment->public_id],
        ], $this->owner, (int) $this->organization->id, true);
        $message = $this->conversations->addMessage($conversation, 'user', 'Посмотри изображение', metadata: ['request_id' => $requestId]);
        $this->attachments->linkMessage([$attachment->public_id], $message, $this->owner);

        $linked = ChatAttachment::query()->findOrFail($attachment->id);
        self::assertSame($message->id, $linked->message_id);
        self::assertSame($conversation->id, $linked->conversation_id);
        self::assertSame($attachment->publicMetadata(), $message->fresh()->metadata['attachments'][0]);
        self::assertArrayNotHasKey('storage_path', $message->fresh()->metadata['attachments'][0]);

        $this->conversations->updateParticipants($conversation, $this->owner, (int) $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'viewer']]);
        $this->files->shouldReceive('readCurrentBounded')->twice()->andReturnUsing(function () {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $this->storedBytes);
            rewind($stream);
            return $stream;
        });
        $parts = $this->attachments->providerParts([$attachment->public_id], $this->owner, (int) $this->organization->id, $requestId);
        self::assertSame('image_url', $parts[0]['type']);
        self::assertStringStartsWith('data:image/png;base64,', $parts[0]['image_url']['url']);
        [$sharedBytes] = $this->attachments->content($attachment->public_id, $this->viewer, (int) $this->organization->id);
        self::assertSame($this->storedBytes, $sharedBytes);

        $this->conversations->updateParticipants($conversation, $this->owner, (int) $this->organization->id, []);
        try {
            $this->attachments->content($attachment->public_id, $this->viewer, (int) $this->organization->id);
            self::fail('Removing a conversation participant must revoke attachment access immediately.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('ai_chat_attachments', ['id' => $attachment->id, 'message_id' => $message->id]);
        }
        $this->conversations->updateParticipants($conversation, $this->owner, (int) $this->organization->id, [['user_id' => $this->viewer->id, 'role' => 'viewer']]);
        DB::table('organization_user')->where('organization_id', $this->organization->id)->where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->attachments->content($attachment->public_id, $this->viewer, (int) $this->organization->id);
    }

    public function test_duplicate_or_more_than_two_attachment_ids_are_rejected(): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        foreach ([[$id, $id], [$id, (string) \Illuminate\Support\Str::uuid(), (string) \Illuminate\Support\Str::uuid()]] as $ids) {
            try {
                $this->attachments->prepareRequest(['request_id' => (string) \Illuminate\Support\Str::uuid(), 'attachment_ids' => $ids], $this->owner, (int) $this->organization->id);
                self::fail('Duplicate and excessive attachment lists must be rejected.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_manifest_linkage_and_provider_parts_preserve_attachment_input_order(): void
    {
        $conversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $firstId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $secondId = '00000000-0000-4000-8000-000000000001';
        $firstBytes = 'first-image-payload';
        $secondBytes = 'second-image-payload';
        $firstPath = 'org-'.$this->organization->id.'/ai-assistant/chat-images/'.$firstId.'.png';
        $secondPath = 'org-'.$this->organization->id.'/ai-assistant/chat-images/'.$secondId.'.png';
        $bytesByPath = [$firstPath => $firstBytes, $secondPath => $secondBytes];
        $ids = [$firstId, $secondId];

        foreach ([[$firstId, $firstBytes, $firstPath, 'first.png'], [$secondId, $secondBytes, $secondPath, 'second.png']] as [$id, $bytes, $path, $name]) {
            ChatAttachment::query()->create([
                'public_id' => $id,
                'organization_id' => $this->organization->id,
                'user_id' => $this->owner->id,
                'conversation_id' => $conversation->id,
                'name' => $name,
                'mime' => 'image/png',
                'size' => strlen($bytes),
                'width' => 1,
                'height' => 1,
                'checksum' => hash('sha256', $bytes),
                'storage_path' => $path,
            ]);
        }

        $prepared = $this->attachments->prepareRequest([
            'request_id' => $requestId,
            'conversation_id' => $conversation->id,
            'attachment_ids' => $ids,
        ], $this->owner, (int) $this->organization->id, true);
        self::assertSame($ids, array_column($prepared['attachment_manifest'], 'id'));

        $message = $this->conversations->addMessage($conversation, 'user', 'Сравни первое и второе', metadata: ['request_id' => $requestId]);
        $this->attachments->linkMessage($ids, $message, $this->owner);
        self::assertSame($ids, array_column($message->fresh()->metadata['attachments'], 'id'));

        $this->files->shouldReceive('readCurrentBounded')->twice()->andReturnUsing(static function (string $key) use ($bytesByPath) {
            self::assertArrayHasKey($key, $bytesByPath);
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $bytesByPath[$key]);
            rewind($stream);
            return $stream;
        });
        $parts = $this->attachments->providerParts($ids, $this->owner, (int) $this->organization->id, $requestId);
        self::assertSame('data:image/png;base64,'.base64_encode($firstBytes), $parts[0]['image_url']['url']);
        self::assertSame('data:image/png;base64,'.base64_encode($secondBytes), $parts[1]['image_url']['url']);
    }

    public function test_quote_request_hash_binds_attachment_order_and_rejects_reordered_begin(): void
    {
        $this->files->shouldReceive('putPrivate')->twice()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $first = $this->attachments->upload(UploadedFile::fake()->image('first.png', 8, 8), $this->owner, (int) $this->organization->id);
        $second = $this->attachments->upload(UploadedFile::fake()->image('second.png', 8, 8), $this->owner, (int) $this->organization->id);
        $credits = new AICreditService;
        config()->set('ai-assistant-credits.enforce', true);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $base = ['request_id' => $requestId, 'message' => 'Сравни первое и второе', 'profile' => 'short'];
        $ids = [$first->public_id, $second->public_id];
        $reversedIds = array_reverse($ids);
        $firstPayload = $this->attachments->prepareRequest($base + ['attachment_ids' => $ids], $this->owner, (int) $this->organization->id);
        $reversedPayload = $this->attachments->prepareRequest($base + ['attachment_ids' => $reversedIds], $this->owner, (int) $this->organization->id);
        self::assertSame($ids, array_column($firstPayload['attachment_manifest'], 'id'));
        self::assertSame($reversedIds, array_column($reversedPayload['attachment_manifest'], 'id'));

        $firstQuote = $credits->quote($this->organization, $this->owner, $base + ['attachment_ids' => $ids]);
        $reversedQuote = $credits->quote($this->organization, $this->owner, $base + ['attachment_ids' => $reversedIds]);
        $firstRecord = AICreditQuote::query()->where('public_id', $firstQuote['quote_id'])->firstOrFail();
        $reversedRecord = AICreditQuote::query()->where('public_id', $reversedQuote['quote_id'])->firstOrFail();

        self::assertSame($credits->canonicalAssistantRequest($firstPayload), $firstRecord->request_hash);
        self::assertSame($credits->canonicalAssistantRequest($reversedPayload), $reversedRecord->request_hash);
        self::assertNotSame($firstRecord->request_hash, $reversedRecord->request_hash);
        self::assertNotSame($firstQuote['quote_id'], $reversedQuote['quote_id']);

        $credits->grant($this->organization, 1_000_000, 'purchase', null, 'image-order-'.$requestId);
        try {
            $credits->begin($this->organization, $this->owner, $firstQuote['quote_id'], $requestId, null, $base + ['attachment_ids' => $reversedIds]);
            self::fail('An approved quote must reject the reversed image order.');
        } catch (\DomainException $exception) {
            self::assertSame('AI credit quote is invalid or expired.', $exception->getMessage());
        }
        self::assertDatabaseMissing('ai_credit_reservations', ['request_id' => $requestId]);
        foreach ($ids as $id) {
            self::assertDatabaseHas('ai_chat_attachments', ['public_id' => $id, 'request_id' => null]);
        }

        $reservation = $credits->begin($this->organization, $this->owner, $firstQuote['quote_id'], $requestId, null, $base + ['attachment_ids' => $ids]);
        self::assertSame((int) $firstRecord->id, (int) $reservation->ai_credit_quote_id);
        self::assertSame((int) $firstRecord->max_units_minor, $reservation->reserved_minor);
        self::assertDatabaseHas('ai_credit_reservations', ['request_id' => $requestId, 'ai_credit_quote_id' => $firstRecord->id]);
        foreach ($ids as $id) {
            self::assertDatabaseHas('ai_chat_attachments', ['public_id' => $id, 'request_id' => $requestId]);
        }
    }

    public function test_retention_preview_does_not_delete_and_purge_removes_linked_and_expired_orphan_files(): void
    {
        $conversation = $this->conversations->createConversation((int) $this->organization->id, $this->owner);
        $this->files->shouldReceive('putPrivate')->twice()->andReturnUsing(static fn (string $key, string $bytes, string $mime, string $checksum): CurrentStoredFile => new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $mime));
        $linked = $this->attachments->upload(UploadedFile::fake()->image('linked.png', 10, 10), $this->owner, (int) $this->organization->id, (int) $conversation->id);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->attachments->prepareRequest(['request_id' => $requestId, 'conversation_id' => $conversation->id, 'attachment_ids' => [$linked->public_id]], $this->owner, (int) $this->organization->id, true);
        $message = $this->conversations->addMessage($conversation, 'user', 'Фото', metadata: ['request_id' => $requestId]);
        $this->attachments->linkMessage([$linked->public_id], $message, $this->owner);
        $orphan = $this->attachments->upload(UploadedFile::fake()->image('orphan.png', 10, 10), $this->owner, (int) $this->organization->id);
        ChatAttachment::query()->whereKey($orphan->id)->update(['created_at' => now()->subDay()->subSecond()]);
        $conversation->forceFill(['last_activity_at' => now()->subDays(91)])->save();

        $previewFiles = Mockery::mock(FileService::class);
        $previewFiles->shouldNotReceive('delete');
        $preview = (new AssistantRetentionService($previewFiles))->preview();
        self::assertSame(2, $preview['attachments']);
        self::assertSame(2, $preview['files']);
        self::assertDatabaseHas('ai_chat_attachments', ['id' => $linked->id]);
        self::assertDatabaseHas('ai_chat_attachments', ['id' => $orphan->id]);

        $purgeFiles = Mockery::mock(FileService::class);
        $purgeFiles->shouldReceive('delete')->twice()->withArgs(fn (string $path, Organization $organization): bool => in_array($path, [$linked->storage_path, $orphan->storage_path], true) && $organization->is($this->organization))->andReturn(true);
        $purged = (new AssistantRetentionService($purgeFiles))->purge();
        self::assertSame(2, $purged['attachments']);
        self::assertSame(2, $purged['files']);
        self::assertDatabaseMissing('ai_chat_attachments', ['id' => $linked->id]);
        self::assertDatabaseMissing('ai_chat_attachments', ['id' => $orphan->id]);
        self::assertDatabaseMissing('ai_conversations', ['id' => $conversation->id]);
    }

    private function member(): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $user->organizations()->attach($this->organization->id, ['is_active' => true]);
        return $user;
    }

    private function assertCanonicalUpload(UploadedFile $image, string $mime): void
    {
        $this->files->shouldReceive('putPrivate')->once()->andReturnUsing(static function (string $key, string $bytes, string $storedMime, string $checksum) use ($mime): CurrentStoredFile {
            self::assertSame($mime, $storedMime);
            self::assertSame($mime, (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
            self::assertSame(hash('sha256', $bytes), $checksum);
            return new CurrentStoredFile($key, 'etag', strlen($bytes), $checksum, $storedMime);
        });
        $attachment = $this->attachments->upload($image, $this->owner, (int) $this->organization->id);

        self::assertSame($mime, $attachment->mime);
        self::assertSame(30, $attachment->width);
        self::assertSame(18, $attachment->height);
    }
}
