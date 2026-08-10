<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class EloquentEvaluationCorpusRepository implements EvaluationCorpusRepository
{
    private const TABLE = 'estimate_generation_evaluation_examples';

    public function __construct(private Connection $database) {}

    public function addCandidate(EvaluationExample $example): EvaluationExample
    {
        return $this->database->transaction(function () use ($example): EvaluationExample {
            $this->database->table(self::TABLE)->insertOrIgnore([
                ...$this->examplePayload($example),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stored = $this->query($example->organizationId, $example->sourceVersion)
                ->lockForUpdate()
                ->first();
            if (! $stored instanceof stdClass) {
                throw new DomainException('Evaluation example persistence failed.');
            }

            $persisted = $this->hydrate($stored);
            if (! hash_equals($persisted->fingerprint(), $example->fingerprint())) {
                throw new DomainException('Evaluation source version collision.');
            }

            return $persisted;
        }, 3);
    }

    public function find(int $organizationId, string $sourceVersion): ?EvaluationExample
    {
        $row = $this->query($organizationId, $sourceVersion)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function transition(
        int $organizationId,
        string $sourceVersion,
        EvaluationReviewDecision $decision,
    ): EvaluationExample {
        $decision = $this->canonicalReviewDecision($decision);

        return $this->database->transaction(function () use (
            $organizationId,
            $sourceVersion,
            $decision,
        ): EvaluationExample {
            $row = $this->query($organizationId, $sourceVersion)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new InvalidArgumentException('Evaluation example was not found.');
            }

            $example = $this->hydrate($row);
            if ($example->reviewDecision !== null) {
                if ($this->canonicalReviewDecision($example->reviewDecision) == $decision) {
                    return $example;
                }

                throw new DomainException('Evaluation review decision is immutable.');
            }

            $updated = $this->query($organizationId, $sourceVersion)
                ->where('trust_status', EvaluationExampleTrust::Candidate->value)
                ->update([
                    'trust_status' => $decision->trustStatus->value,
                    'review_actor_type' => $decision->actorType,
                    'review_actor_id' => $decision->actorId,
                    'review_reason' => $decision->reason,
                    'reviewed_at' => $decision->decidedAt->format('Y-m-d H:i:sP'),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('Evaluation review transition failed.');
            }

            return $example->withReviewDecision($decision);
        }, 3);
    }

    private function canonicalReviewDecision(EvaluationReviewDecision $decision): EvaluationReviewDecision
    {
        $decidedAt = $decision->decidedAt->setTimezone(new DateTimeZone('UTC'));

        return new EvaluationReviewDecision(
            $decision->trustStatus,
            $decision->actorType,
            $decision->actorId,
            trim($decision->reason),
            $decidedAt->setTime(
                (int) $decidedAt->format('H'),
                (int) $decidedAt->format('i'),
                (int) $decidedAt->format('s'),
            ),
        );
    }

    public function reviewed(int $organizationId): array
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('Evaluation organization is invalid.');
        }

        return $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('trust_status', EvaluationExampleTrust::Reviewed->value)
            ->orderBy('source_version')
            ->get()
            ->map(fn (stdClass $row): EvaluationExample => $this->hydrate($row))
            ->all();
    }

    private function query(int $organizationId, string $sourceVersion): Builder
    {
        if ($organizationId < 1) {
            throw new InvalidArgumentException('Evaluation organization is invalid.');
        }

        return $this->database->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('source_version', $sourceVersion);
    }

    /** @return array<string, mixed> */
    private function examplePayload(EvaluationExample $example): array
    {
        return [
            'organization_id' => $example->organizationId,
            'source_version' => $example->sourceVersion,
            'expected_facts' => $this->encode($example->expectedFacts),
            'expected_decisions' => $this->encode($example->expectedDecisions),
            'expected_quantities' => $this->encode($example->expectedQuantities),
            'expected_estimate_rows' => $this->encode($example->expectedEstimateRows),
            'contract_versions' => $this->encode($example->contractVersions),
            'trust_status' => $example->trustStatus->value,
            'dataset_split' => $example->split,
            'content_fingerprint' => $example->fingerprint(),
        ];
    }

    private function hydrate(stdClass $row): EvaluationExample
    {
        $trust = EvaluationExampleTrust::from((string) $row->trust_status);
        $decision = $trust === EvaluationExampleTrust::Candidate
            ? null
            : new EvaluationReviewDecision(
                $trust,
                (string) $row->review_actor_type,
                (int) $row->review_actor_id,
                (string) $row->review_reason,
                new DateTimeImmutable((string) $row->reviewed_at),
            );

        return new EvaluationExample(
            organizationId: (int) $row->organization_id,
            sourceVersion: (string) $row->source_version,
            expectedFacts: $this->decode($row->expected_facts),
            expectedDecisions: $this->decode($row->expected_decisions),
            expectedQuantities: $this->decode($row->expected_quantities),
            expectedEstimateRows: $this->decode($row->expected_estimate_rows),
            contractVersions: $this->decode($row->contract_versions),
            trustStatus: $trust,
            split: (string) $row->dataset_split,
            reviewDecision: $decision,
        );
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new DomainException('Evaluation example payload is invalid.');
        }

        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DomainException('Evaluation example payload is invalid.');
        }
        if (! is_array($decoded)) {
            throw new DomainException('Evaluation example payload is invalid.');
        }

        return $decoded;
    }
}
