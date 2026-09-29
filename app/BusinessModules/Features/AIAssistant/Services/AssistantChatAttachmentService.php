<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\ChatAttachment;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class AssistantChatAttachmentService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly FileService $files, private readonly ConversationManager $conversations, private readonly AIPermissionChecker $permissions) {}

    public function upload(UploadedFile $image, User $actor, int $organizationId, ?int $conversationId = null): ChatAttachment
    {
        $this->assertActor($actor, $organizationId);
        $this->assertConversation($conversationId, $actor, $organizationId, true);
        if (!$image->isValid() || ($image->getSize() ?: 0) > self::MAX_BYTES) {
            $this->invalid();
        }
        $bytes = file_get_contents($image->getPathname(), false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes)) {
            $this->invalid();
        }
        [$mime, $width, $height] = $this->validateBytes($bytes);
        if (!function_exists('imagecreatefromstring') || ($mime === 'image/webp' && !function_exists('imagewebp'))) {
            $this->invalid();
        }
        $decodeWarning = false;
        set_error_handler(static function () use (&$decodeWarning): bool { $decodeWarning = true; return true; });
        try {
            $decoded = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($decoded === false || $decodeWarning) {
            if ($decoded !== false) { imagedestroy($decoded); }
            $this->invalid();
        }
        ob_start();
        try {
            $encoded = match ($mime) {
                'image/png' => imagepng($decoded, null, 6),
                'image/jpeg' => imagejpeg($decoded, null, 95),
                'image/webp' => imagewebp($decoded, null, 95),
            };
            $clean = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($decoded);
        }
        if (!$encoded || !is_string($clean) || strlen($clean) > self::MAX_BYTES) {
            $this->invalid();
        }
        $id = (string) Str::uuid();
        $checksum = hash('sha256', $clean);
        $extension = match ($mime) {'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'};
        $path = 'org-'.$organizationId.'/ai-assistant/chat-images/'.$id.'.'.$extension;
        $this->files->putPrivate($path, $clean, $mime, $checksum);
        try {
            return ChatAttachment::query()->create([
                'public_id' => $id, 'organization_id' => $organizationId, 'user_id' => $actor->id, 'conversation_id' => $conversationId,
                'name' => mb_substr((string) preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', $image->getClientOriginalName()), 0, 255),
                'mime' => $mime, 'size' => strlen($clean), 'width' => $width, 'height' => $height, 'checksum' => $checksum, 'storage_path' => $path,
            ]);
        } catch (\Throwable $exception) {
            $this->files->delete($path, Organization::query()->findOrFail($organizationId));
            throw $exception;
        }
    }

    public function prepareRequest(array $payload, User $actor, int $organizationId, bool $bind = false): array
    {
        if ($bind && DB::transactionLevel() === 0) {
            return DB::transaction(fn (): array => $this->prepareRequest($payload, $actor, $organizationId, true), 3);
        }
        $ids = $payload['attachment_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 2 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            $this->invalid();
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !Str::isUuid($id)) {
                $this->invalid();
            }
        }
        $ids = array_map('strtolower', $ids);
        sort($ids, SORT_STRING);
        unset($payload['attachment_manifest']);
        if ($ids === []) {
            unset($payload['attachment_ids']);
            return $payload;
        }
        $this->assertActor($actor, $organizationId);
        $conversationId = isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null;
        $this->assertConversation($conversationId, $actor, $organizationId, true);
        $requestId = (string) ($payload['request_id'] ?? '');
        if (!Str::isUuid($requestId)) {
            $this->invalid();
        }
        $rows = ChatAttachment::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)->whereIn('public_id', $ids)->orderBy('public_id')->when($bind, fn ($query) => $query->lockForUpdate())->get();
        if ($rows->count() !== count($ids)) {
            throw new AuthorizationException(trans_message('ai_assistant.attachment_access_denied'));
        }
        $manifest = [];
        foreach ($rows as $row) {
            if (($row->request_id !== null && $row->request_id !== $requestId)
                || ($row->conversation_id !== null && $row->conversation_id !== $conversationId && !($conversationId === null && $row->request_id === $requestId))
                || ($row->message_id === null && $row->created_at->lte(now()->subDay()))) {
                $this->invalid();
            }
            $this->assertConversation($row->conversation_id, $actor, $organizationId, true);
            $manifest[] = ['id' => $row->public_id, 'checksum' => $row->checksum, 'mime' => $row->mime, 'size' => $row->size, 'width' => $row->width, 'height' => $row->height];
            if ($bind) {
                $row->forceFill(['request_id' => $requestId])->save();
            }
        }
        $payload['attachment_ids'] = $ids;
        $payload['attachment_manifest'] = $manifest;
        return $payload;
    }

    public function linkMessage(array $ids, Message $message, User $actor): void
    {
        if ($ids === []) {
            return;
        }
        DB::transaction(function () use ($ids, $message, $actor): void {
            $conversation = $message->conversation;
            $payload = $this->prepareRequest(['attachment_ids' => $ids, 'conversation_id' => $conversation->id, 'request_id' => $message->metadata['request_id'] ?? null], $actor, (int) $conversation->organization_id, true);
            $metadata = [];
            foreach ($payload['attachment_ids'] as $id) {
                $row = ChatAttachment::query()->where('public_id', $id)->lockForUpdate()->firstOrFail();
                if ($row->message_id !== null && $row->message_id !== (int) $message->id) {
                    $this->invalid();
                }
                $row->forceFill(['conversation_id' => $conversation->id, 'message_id' => $message->id])->save();
                $metadata[] = $row->publicMetadata();
            }
            $message->forceFill(['metadata' => array_merge($message->metadata ?? [], ['attachments' => $metadata])])->save();
        });
    }

    public function providerParts(array $ids, User $actor, int $organizationId, string $requestId): array
    {
        $parts = [];
        foreach ($ids as $id) {
            $row = ChatAttachment::query()->where('public_id', $id)->where('organization_id', $organizationId)->where('user_id', $actor->id)->where('request_id', $requestId)->firstOrFail();
            if ($row->message_id === null) {
                $this->invalid();
            }
            $this->assertConversation($row->conversation_id, $actor, $organizationId, true);
            [$bytes, $mime] = $this->content($id, $actor, $organizationId);
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'detail' => 'high']];
        }
        return $parts;
    }

    public function content(string $id, User $actor, int $organizationId): array
    {
        $this->assertActor($actor, $organizationId);
        $row = ChatAttachment::query()->where('public_id', $id)->where('organization_id', $organizationId)->firstOrFail();
        if ($row->message_id === null) {
            if ($row->user_id !== (int) $actor->id || $row->created_at->lte(now()->subDay())) {
                throw new AuthorizationException(trans_message('ai_assistant.attachment_access_denied'));
            }
            $this->assertConversation($row->conversation_id, $actor, $organizationId, false);
        } else {
            $conversation = $this->conversations->findAccessibleConversation((int) $row->conversation_id, $actor, $organizationId);
            $message = Message::query()->find($row->message_id);
            if ($conversation === null || $message === null || !$this->conversations->canReadMessage($message, $conversation, $actor)) {
                throw new AuthorizationException(trans_message('ai_assistant.attachment_access_denied'));
            }
        }
        $stream = $this->files->readCurrentBounded($row->storage_path, 15, self::MAX_BYTES + 1);
        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if (!is_string($bytes) || strlen($bytes) !== $row->size || !hash_equals($row->checksum, hash('sha256', $bytes))) {
            throw new RuntimeException('assistant_attachment_integrity_failed');
        }
        return [$bytes, $row->mime];
    }

    private function assertActor(User $actor, int $organizationId): void
    {
        if (!$actor->is_active || (int) $actor->current_organization_id !== $organizationId || !$actor->belongsToOrganization($organizationId) || !$this->permissions->canUseAssistant($actor, $organizationId)) {
            throw new AuthorizationException(trans_message('ai_assistant.attachment_access_denied'));
        }
    }

    private function assertConversation(?int $conversationId, User $actor, int $organizationId, bool $write): void
    {
        if ($conversationId !== null && $this->conversations->findAccessibleConversation($conversationId, $actor, $organizationId, $write) === null) {
            throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
        }
    }

    private function validateBytes(string $bytes): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        if (strlen($bytes) < 12 || strlen($bytes) > self::MAX_BYTES || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info === false || ($info['mime'] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048
            || $info[0] * $info[1] > 4194304 || preg_match('/<\?(?:php|=)|<script|<svg|<!DOCTYPE/i', $bytes)) {
            $this->invalid();
        }
        if (($mime === 'image/jpeg' && !$this->validJpegContainer($bytes))
            || ($mime === 'image/webp' && (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP' || unpack('Vsize', substr($bytes, 4, 4))['size'] + 8 !== strlen($bytes)))) {
            $this->invalid();
        }
        if ($mime === 'image/png') {
            $offset = 8;
            do {
                if ($offset + 12 > strlen($bytes)) { $this->invalid(); }
                $length = unpack('Nlength', substr($bytes, $offset, 4))['length'];
                if ($length > strlen($bytes) - $offset - 12) { $this->invalid(); }
                $chunk = substr($bytes, $offset + 4, $length + 4);
                if (!hash_equals(hash('crc32b', $chunk, true), substr($bytes, $offset + $length + 8, 4))) { $this->invalid(); }
                $type = substr($chunk, 0, 4);
                if ($type === 'acTL') { $this->invalid(); }
                $offset += $length + 12;
            } while ($type !== 'IEND');
            if ($offset !== strlen($bytes)) { $this->invalid(); }
        }
        $limit = ini_get('memory_limit');
        if (is_string($limit) && $limit !== '-1') {
            $bytesLimit = (int) $limit * match (strtolower(substr($limit, -1))) {'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1};
            if ($bytesLimit - memory_get_usage(true) < 64 * 1024 * 1024) { $this->invalid(); }
        }
        return [$mime, $info[0], $info[1]];
    }

    private function validJpegContainer(string $bytes): bool
    {
        if (!str_starts_with($bytes, "\xff\xd8")) { return false; }
        $offset = 2;
        $scan = false;
        $size = strlen($bytes);
        while ($offset < $size) {
            if ($scan) {
                $next = strpos($bytes, "\xff", $offset);
                if ($next === false) { return false; }
                $offset = $next;
            }
            if ($bytes[$offset] !== "\xff") { return false; }
            do { $offset++; } while ($offset < $size && $bytes[$offset] === "\xff");
            if ($offset >= $size) { return false; }
            $marker = ord($bytes[$offset++]);
            if ($scan && ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7))) { continue; }
            if ($marker === 0xd9) { return $offset === $size; }
            if ($marker === 0xd8 || $marker === 0 || $offset + 2 > $size) { return false; }
            $length = unpack('Nlength', "\x00\x00".substr($bytes, $offset, 2))['length'];
            if ($length < 2 || $length > $size - $offset) { return false; }
            $offset += $length;
            $scan = $marker === 0xda;
        }
        return false;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['attachment_ids' => trans_message('ai_assistant.attachment_invalid')]);
    }
}
