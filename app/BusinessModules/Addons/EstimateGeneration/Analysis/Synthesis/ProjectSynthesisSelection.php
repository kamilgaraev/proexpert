<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use InvalidArgumentException;

final readonly class ProjectSynthesisSelection
{
    /** @param list<string> $acceptedLinkIds @param list<string> $questionConflictIds */
    public function __construct(
        public array $acceptedLinkIds,
        public array $questionConflictIds,
    ) {
        foreach ([...$acceptedLinkIds, ...$questionConflictIds] as $id) {
            if (! is_string($id) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,159}$/D', $id) !== 1) {
                throw new InvalidArgumentException('project_synthesis_selection_invalid');
            }
        }
        if (count(array_unique($acceptedLinkIds)) !== count($acceptedLinkIds)
            || count(array_unique($questionConflictIds)) !== count($questionConflictIds)) {
            throw new InvalidArgumentException('project_synthesis_selection_duplicate');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (array_keys($payload) !== ['accepted_link_ids', 'question_conflict_ids']
            || ! array_is_list($payload['accepted_link_ids'])
            || ! array_is_list($payload['question_conflict_ids'])) {
            throw new InvalidArgumentException('project_synthesis_selection_invalid');
        }

        return new self($payload['accepted_link_ids'], $payload['question_conflict_ids']);
    }

    /** @return array{accepted_link_ids:list<string>,question_conflict_ids:list<string>} */
    public function toArray(): array
    {
        $links = $this->acceptedLinkIds;
        $questions = $this->questionConflictIds;
        sort($links, SORT_STRING);
        sort($questions, SORT_STRING);

        return ['accepted_link_ids' => $links, 'question_conflict_ids' => $questions];
    }
}
