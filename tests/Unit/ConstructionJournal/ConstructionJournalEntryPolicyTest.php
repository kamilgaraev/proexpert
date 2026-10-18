<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Policies\ConstructionJournalEntryPolicy;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;

class ConstructionJournalEntryPolicyTest extends TestCase
{
    private Container $previousContainer;

    private Container $container;

    private int $accessResolutions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->container = new Container;
        Container::setInstance($this->container);
        $this->container->bind(ConstructionJournalAccessService::class, function (): never {
            $this->accessResolutions++;

            throw new LogicException('fresh_journal_access_expected');
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_terminal_entries_deny_mutations_without_authorization_work(): void
    {
        $policy = new ConstructionJournalEntryPolicy;
        $actor = $this->actor();

        foreach ([JournalEntryStatusEnum::SUBMITTED, JournalEntryStatusEnum::APPROVED] as $status) {
            $entry = $this->entry($status, JournalStatusEnum::ACTIVE);
            self::assertFalse($policy->update($actor, $entry), $status->value);
            self::assertFalse($policy->delete($actor, $entry), $status->value);
        }

        self::assertSame(0, $this->accessResolutions);
    }

    public function test_inactive_journals_deny_entry_mutations_without_authorization_work(): void
    {
        $policy = new ConstructionJournalEntryPolicy;
        $actor = $this->actor();

        foreach ([JournalStatusEnum::CLOSED, JournalStatusEnum::ARCHIVED] as $journalStatus) {
            foreach (JournalEntryStatusEnum::cases() as $entryStatus) {
                $entry = $this->entry($entryStatus, $journalStatus);
                self::assertFalse($policy->update($actor, $entry), $journalStatus->value.'/'.$entryStatus->value);
                self::assertFalse($policy->delete($actor, $entry), $journalStatus->value.'/'.$entryStatus->value);
            }
        }

        self::assertSame(0, $this->accessResolutions);
    }

    public function test_eligible_mutations_still_resolve_fresh_access_on_every_call(): void
    {
        $policy = new ConstructionJournalEntryPolicy;
        $actor = $this->actor();
        $checks = [
            ['update', JournalEntryStatusEnum::DRAFT],
            ['update', JournalEntryStatusEnum::REJECTED],
            ['delete', JournalEntryStatusEnum::DRAFT],
        ];

        foreach ($checks as $index => [$ability, $status]) {
            $actor->current_organization_id = $index + 1;
            try {
                $policy->{$ability}($actor, $this->entry($status, JournalStatusEnum::ACTIVE));
                self::fail('Eligible mutation skipped fresh access');
            } catch (LogicException $exception) {
                self::assertSame('fresh_journal_access_expected', $exception->getMessage());
            }
            self::assertSame($index + 1, $this->accessResolutions);
        }
    }

    public function test_gate_hooks_still_observe_denied_terminal_actions(): void
    {
        $actor = $this->actor();
        $gate = new Gate($this->container, static fn (): User => $actor);
        $gate->policy(ConstructionJournalEntry::class, ConstructionJournalEntryPolicy::class);
        $before = [];
        $after = [];
        $gate->before(static function (User $user, string $ability) use (&$before): void {
            $before[] = $ability;
        });
        $gate->after(static function (User $user, string $ability, ?bool $result) use (&$after): void {
            $after[$ability] = $result;
        });
        $entry = $this->entry(JournalEntryStatusEnum::APPROVED, JournalStatusEnum::ACTIVE);

        self::assertFalse($gate->allows('update', $entry));
        self::assertFalse($gate->allows('delete', $entry));
        self::assertSame(['update', 'delete'], $before);
        self::assertSame(['update' => false, 'delete' => false], $after);
        self::assertSame(0, $this->accessResolutions);
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 1, 'current_organization_id' => 1]);
    }

    private function entry(JournalEntryStatusEnum $entryStatus, JournalStatusEnum $journalStatus): ConstructionJournalEntry
    {
        $project = (new Project)->forceFill(['id' => 10, 'organization_id' => 1]);
        $journal = (new ConstructionJournal)->forceFill(['id' => 20, 'status' => $journalStatus, 'performing_organization_id' => 1]);
        $journal->setRelation('project', $project);
        $entry = (new ConstructionJournalEntry)->forceFill(['id' => 30, 'status' => $entryStatus, 'created_by_user_id' => 1]);
        $entry->setRelation('journal', $journal);

        return $entry;
    }
}
