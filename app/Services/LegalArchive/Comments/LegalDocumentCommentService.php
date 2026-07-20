<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Comments;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentComment;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class LegalDocumentCommentService
{
    private readonly LegalDocumentAggregateLock $aggregateLock;

    private readonly ImmutableAuditIntegrityService $integrity;

    public function __construct(
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalDocumentAudit $audit,
        private readonly ConnectionInterface $connection,
        ?LegalDocumentAggregateLock $aggregateLock = null,
        ?ImmutableAuditIntegrityService $integrity = null,
    ) {
        $this->aggregateLock = $aggregateLock ?? new LegalDocumentAggregateLock;
        $this->integrity = $integrity ?? new ImmutableAuditIntegrityService;
    }

    public function create(
        LegalArchiveDocument $document,
        User $actor,
        int $versionId,
        string $body,
        ?int $pageNumber = null,
        ?array $anchor = null,
        string $visibility = 'internal',
        bool $blocking = false,
        ?string $idempotencyKey = null,
    ): LegalDocumentComment {
        $this->access->authorize($actor, $document, 'comment');
        if ((int) $actor->current_organization_id !== (int) $document->organization_id && $visibility !== 'all_parties') {
            throw new AuthorizationException($this->message('comment_visibility_denied'));
        }
        $body = trim($body);
        $this->validateCreate($body, $pageNumber, $anchor, $visibility, $idempotencyKey);
        $requestHash = $this->hash([
            'version_id' => $versionId,
            'body' => $body,
            'page_number' => $pageNumber,
            'anchor' => $anchor,
            'visibility' => $visibility,
            'is_blocking' => $blocking,
        ]);

        return $this->connection->transaction(function () use (
            $document, $actor, $versionId, $body, $pageNumber, $anchor, $visibility, $blocking,
            $idempotencyKey, $requestHash,
        ): LegalDocumentComment {
            $lockedDocument = $this->aggregateLock->lockDocument(
                $this->connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $version = $this->lockVersion($lockedDocument, $versionId);
            if ($idempotencyKey !== null) {
                $existing = $this->commentQuery($lockedDocument)
                    ->where('author_user_id', (int) $actor->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof LegalDocumentComment) {
                    return $this->replay($existing, $requestHash);
                }
            }

            $comment = LegalDocumentComment::query()->create([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'document_version_id' => (int) $version->id,
                'author_user_id' => (int) $actor->id,
                'body' => $body,
                'page_number' => $pageNumber,
                'anchor' => $anchor,
                'visibility' => $visibility,
                'is_blocking' => $blocking,
                'status' => 'open',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            $this->audit->record('comment_created', $lockedDocument, $actor, [
                'comment_id' => (int) $comment->id,
                'version_id' => (int) $version->id,
                'visibility' => $visibility,
                'is_blocking' => $blocking,
                'source_event_id' => $idempotencyKey === null
                    ? null
                    : "comment:create:actor:{$actor->id}:{$idempotencyKey}",
                'idempotency_key' => $idempotencyKey,
            ]);

            return $comment;
        });
    }

    public function resolve(
        LegalArchiveDocument $document,
        LegalDocumentComment $comment,
        User $actor,
        ?string $resolution = null,
        ?string $idempotencyKey = null,
    ): LegalDocumentComment {
        $this->access->authorize($actor, $document, 'comment');
        $resolution = $resolution === null ? null : trim($resolution);
        if ($resolution !== null && mb_strlen($resolution) > 5000) {
            throw new DomainException('legal_document_comment_resolution_invalid');
        }
        $this->validateIdempotencyKey($idempotencyKey);
        $requestHash = $this->hash(['resolution' => $resolution]);

        return $this->connection->transaction(function () use (
            $document, $comment, $actor, $resolution, $idempotencyKey, $requestHash,
        ): LegalDocumentComment {
            $lockedDocument = $this->aggregateLock->lockDocument(
                $this->connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $locked = $this->visibleQuery($lockedDocument, $actor)
                ->whereKey((int) $comment->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof LegalDocumentComment) {
                throw new DomainException('legal_document_comment_not_found');
            }
            $responsible = (int) $lockedDocument->responsible_user_id === (int) $actor->id;
            $author = (int) $locked->author_user_id === (int) $actor->id;
            if (($locked->is_blocking && ! $responsible) || (! $locked->is_blocking && ! $responsible && ! $author)) {
                throw new AuthorizationException($this->message('document_not_found'));
            }
            $this->lockVersion($lockedDocument, (int) $locked->document_version_id);
            if ($locked->status === 'resolved') {
                if (
                    $idempotencyKey !== null
                    && hash_equals((string) $locked->resolution_idempotency_key, $idempotencyKey)
                    && hash_equals((string) $locked->resolution_request_hash, $requestHash)
                ) {
                    return $locked;
                }
                throw new DomainException('legal_document_comment_already_resolved');
            }

            $locked->forceFill([
                'status' => 'resolved',
                'resolution' => $resolution,
                'resolved_by_user_id' => (int) $actor->id,
                'resolved_at' => now(),
                'resolution_idempotency_key' => $idempotencyKey,
                'resolution_request_hash' => $requestHash,
            ])->save();
            $this->audit->record('comment_resolved', $lockedDocument, $actor, [
                'comment_id' => (int) $locked->id,
                'version_id' => (int) $locked->document_version_id,
                'source_event_id' => $idempotencyKey === null
                    ? null
                    : "comment:resolve:comment:{$locked->id}:{$idempotencyKey}",
                'idempotency_key' => $idempotencyKey,
            ]);

            return $locked;
        });
    }

    public function visible(LegalArchiveDocument $document, User $actor, ?int $versionId = null): Collection
    {
        $this->access->authorize($actor, $document, 'comment');
        $query = $this->visibleQuery($document, $actor);
        if ($versionId !== null) {
            $query->where('document_version_id', $versionId);
        }

        return $query->orderBy('id')->get();
    }

    public function findVisible(LegalArchiveDocument $document, User $actor, int $commentId): LegalDocumentComment
    {
        $this->access->authorize($actor, $document, 'comment');
        $comment = $this->visibleQuery($document, $actor)->whereKey($commentId)->first();
        if (! $comment instanceof LegalDocumentComment) {
            throw new DomainException('legal_document_comment_not_found');
        }

        return $comment;
    }

    private function validateCreate(
        string $body,
        ?int $pageNumber,
        ?array $anchor,
        string $visibility,
        ?string $idempotencyKey,
    ): void {
        if ($body === '' || mb_strlen($body) > 10000) {
            throw new DomainException('legal_document_comment_body_invalid');
        }
        if ($pageNumber !== null && $pageNumber < 1) {
            throw new DomainException('legal_document_comment_page_invalid');
        }
        if (LegalDocumentCommentVisibility::tryFrom($visibility) === null) {
            throw new DomainException('legal_document_comment_visibility_invalid');
        }
        if ($anchor !== null) {
            $this->validateAnchor($anchor);
        }
        $this->validateIdempotencyKey($idempotencyKey);
    }

    private function validateAnchor(array $anchor): void
    {
        if (($anchor['type'] ?? null) !== 'rect' || array_diff_key($anchor, array_flip(['type', 'x', 'y', 'width', 'height'])) !== []) {
            throw new DomainException('legal_document_comment_anchor_invalid');
        }
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $coordinate = $anchor[$key] ?? null;
            if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate)) {
                throw new DomainException('legal_document_comment_anchor_invalid');
            }
            $value = (float) $coordinate;
            if ($value < 0 || $value > 1 || (in_array($key, ['width', 'height'], true) && $value <= 0)) {
                throw new DomainException('legal_document_comment_anchor_invalid');
            }
        }
        if ((float) $anchor['x'] + (float) $anchor['width'] > 1 || (float) $anchor['y'] + (float) $anchor['height'] > 1) {
            throw new DomainException('legal_document_comment_anchor_invalid');
        }
    }

    private function validateIdempotencyKey(?string $key): void
    {
        if ($key !== null && (trim($key) === '' || mb_strlen($key) > 191)) {
            throw new DomainException('legal_document_comment_idempotency_key_invalid');
        }
    }

    private function replay(LegalDocumentComment $comment, string $requestHash): LegalDocumentComment
    {
        if (! hash_equals((string) $comment->request_hash, $requestHash)) {
            throw new DomainException('legal_document_comment_idempotency_conflict');
        }

        return $comment;
    }

    private function commentQuery(LegalArchiveDocument $document)
    {
        return LegalDocumentComment::query()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id);
    }

    private function visibleQuery(LegalArchiveDocument $document, User $actor): Builder
    {
        $query = $this->commentQuery($document);
        if ((int) $actor->current_organization_id !== (int) $document->organization_id) {
            return $query->where('visibility', LegalDocumentCommentVisibility::ALL_PARTIES->value);
        }

        return $query->where(static function (Builder $visibility) use ($actor, $document): void {
            $visibility->whereIn('visibility', [
                LegalDocumentCommentVisibility::INTERNAL->value,
                LegalDocumentCommentVisibility::ALL_PARTIES->value,
            ])->orWhere(static function (Builder $restricted) use ($actor, $document): void {
                $restricted->where('visibility', LegalDocumentCommentVisibility::AUTHOR_AND_RESPONSIBLE->value)
                    ->where(static function (Builder $participant) use ($actor, $document): void {
                        $participant->where('author_user_id', (int) $actor->id);
                        if ((int) $document->responsible_user_id === (int) $actor->id) {
                            $participant->orWhereNotNull('author_user_id');
                        }
                    });
            });
        });
    }

    private function lockVersion(LegalArchiveDocument $document, int $versionId)
    {
        try {
            return $this->aggregateLock->lockVersion($this->connection, $document, $versionId);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'legal_workflow_version_not_ready') {
                throw new DomainException('legal_document_comment_version_not_found', previous: $exception);
            }

            throw $exception;
        }
    }

    private function hash(array $payload): string
    {
        return hash('sha256', $this->integrity->canonicalJson($payload));
    }

    private function message(string $key): string
    {
        return Container::getInstance()->bound('translator')
            ? trans_message("legal_archive.messages.{$key}")
            : "legal_archive.messages.{$key}";
    }
}
