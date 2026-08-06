<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\Enums\CurrencyCode;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use JsonException;

final readonly class ContractSettlementExposureOptionsService
{
    public function __construct(
        private ContractSettlementOwnerSource $source,
        private ContractSettlementExposureBuiltinPublishedReport $published,
        private ContractSettlementCalculator $calculator,
        private SettlementAgingPolicy $agingPolicy,
        private ConnectionInterface $connection,
    ) {}

    /** @return array<string, mixed> */
    public function options(ReportScope $scope, DateTimeImmutable $asOf): array
    {
        if ($this->connection->transactionLevel() > 0) {
            return $this->optionsWithinStableView($scope, $asOf);
        }

        return $this->connection->transaction(function () use ($scope, $asOf): array {
            if ($this->connection->getDriverName() === 'pgsql') {
                $this->connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $this->optionsWithinStableView($scope, $asOf);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function optionsWithinStableView(ReportScope $scope, DateTimeImmutable $asOf): array
    {
        $query = new ReportQuery(
            $this->published->definition()->payload(),
            $scope,
            new ReportFilterSet([]),
            [],
            $asOf,
            'ru-RU',
        );

        try {
            $inputs = $this->source->read($scope, $query);
            $options = $this->assemble($scope, $asOf, $inputs);
        } catch (DomainException|InvalidArgumentException) {
            return $this->unavailable($asOf, 'source_unavailable');
        }

        return [
            'available' => true,
            'reason' => null,
            'as_of' => $this->exactDateTime($asOf),
            ...$options,
        ];
    }

    /**
     * @param  list<ContractSettlementInput>  $inputs
     * @return array<string, mixed>
     */
    private function assemble(ReportScope $scope, DateTimeImmutable $asOf, array $inputs): array
    {
        $contractIds = [];
        $projectIds = [];
        $partyKeys = [];
        $directions = [];
        $currencies = [];
        $agingBuckets = [];
        $paymentDocumentIds = [];
        $minimumDueAt = null;
        $maximumDueAt = null;
        $inputByAllocation = [];

        foreach ($inputs as $input) {
            if (! $input instanceof ContractSettlementInput
                || ($scope->projectIds !== [] && ! in_array($input->projectId, $scope->projectIds, true))) {
                throw new DomainException('contract_settlement_options_scope_invalid');
            }

            $contractIds[$input->contractId] = true;
            if ($input->projectId !== null) {
                $projectIds[$input->projectId] = true;
            }
            $partyKey = $input->partyKey();
            if ($partyKey !== null) {
                $partyKeys[$partyKey] = true;
            }
            $directions[$input->direction] = true;
            $currencies[$input->currency] = true;
            $agingBuckets[$this->calculator->calculate($input, $this->agingPolicy)->agingBucket] = true;
            $inputByAllocation[$input->allocationId] = $input;

            if ($input->dueAt !== null) {
                $minimumDueAt = $minimumDueAt === null || $input->dueAt < $minimumDueAt
                    ? $input->dueAt
                    : $minimumDueAt;
                $maximumDueAt = $maximumDueAt === null || $input->dueAt > $maximumDueAt
                    ? $input->dueAt
                    : $maximumDueAt;
            }
            foreach ($input->sourceRefs as $reference) {
                if (is_array($reference)
                    && ($reference['type'] ?? null) === 'payment_document'
                    && ctype_digit((string) ($reference['id'] ?? ''))) {
                    $paymentDocumentIds[(int) $reference['id']] = true;
                }
            }
        }

        $contractPayloads = $this->ownerPayloads($scope, $asOf, 'contract', array_keys($contractIds));
        $paymentPayloads = $this->ownerPayloads(
            $scope,
            $asOf,
            'payment_document',
            array_keys($paymentDocumentIds),
        );
        $projects = $this->projectOptions($scope, array_keys($projectIds));
        $contracts = $this->contractOptions($contractPayloads, array_keys($contractIds), $inputByAllocation);
        $parties = $this->partyOptions(array_values($inputByAllocation));

        if (count($projects) !== count($projectIds)
            || count($contracts) !== count($contractIds)
            || count($parties) !== count($partyKeys)) {
            throw new DomainException('contract_settlement_options_reference_missing');
        }

        return [
            'period' => [
                'default_from' => null,
                'default_to' => $asOf->setTimezone($scope->timezone)->format('Y-m-d'),
            ],
            'due' => [
                'minimum' => $minimumDueAt?->format('Y-m-d'),
                'maximum' => $maximumDueAt?->format('Y-m-d'),
            ],
            'projects' => $projects,
            'contracts' => $contracts,
            'allocations' => $this->allocationOptions($inputByAllocation, $projects, $contracts),
            'parties' => $parties,
            'directions' => $this->translatedOptions('directions', array_keys($directions)),
            'instruments' => $this->paymentOptions($paymentPayloads, 'document_type'),
            'statuses' => $this->paymentOptions($paymentPayloads, 'status'),
            'currencies' => $this->currencyOptions(array_keys($currencies)),
            'aging_buckets' => $this->agingOptions(array_keys($agingBuckets)),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function ownerPayloads(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        string $ownerType,
        array $ids,
    ): array {
        if ($ids === []) {
            return [];
        }

        $latestIds = $this->connection->table('contract_settlement_owner_versions')
            ->selectRaw('DISTINCT ON (owner_id) id')
            ->where('organization_id', $scope->organizationId)
            ->where('owner_type', $ownerType)
            ->where('occurred_at', '<=', ContractSettlementOwnerTimestamp::database($asOf))
            ->whereIn('owner_id', array_map('strval', $ids))
            ->orderBy('owner_id')
            ->orderByDesc('version');

        $rows = $this->connection->table('contract_settlement_owner_versions')
            ->whereIn('id', $latestIds)
            ->orderBy('owner_id')
            ->get(['owner_id', 'operation', 'payload']);

        $payloads = [];
        foreach ($rows as $row) {
            $ownerId = (int) $row->owner_id;
            if ((string) $row->operation === 'delete') {
                $payloads[$ownerId] = null;

                continue;
            }
            $payloads[$ownerId] = $this->payload($row->payload);
        }

        return array_filter($payloads, 'is_array');
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload)) {
            throw new DomainException('contract_settlement_options_payload_invalid');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('contract_settlement_options_payload_invalid', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new DomainException('contract_settlement_options_payload_invalid');
        }

        return $decoded;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int,name:string}>
     */
    private function projectOptions(ReportScope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connection->table('projects')
            ->whereIn('id', array_intersect($ids, $scope->projectIds))
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => trim((string) $row->name),
            ])
            ->filter(static fn (array $option): bool => $option['name'] !== '')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  list<int>  $ids
     * @param  array<int, ContractSettlementInput>  $inputs
     * @return list<array{id:int,name:string,project_ids:list<int>,party_keys:list<string>,directions:list<string>,currencies:list<string>}>
     */
    private function contractOptions(array $payloads, array $ids, array $inputs): array
    {
        $options = [];
        foreach ($ids as $id) {
            $payload = $payloads[$id] ?? null;
            if (! is_array($payload)) {
                continue;
            }
            $number = trim((string) ($payload['number'] ?? ''));
            $subject = trim((string) ($payload['subject'] ?? ''));
            if ($number === '') {
                continue;
            }
            $projectIds = [];
            $partyKeys = [];
            $directions = [];
            $currencies = [];
            foreach ($inputs as $input) {
                if ($input->contractId !== $id) {
                    continue;
                }
                if ($input->projectId !== null) {
                    $projectIds[$input->projectId] = true;
                }
                $partyKey = $input->partyKey();
                if ($partyKey !== null) {
                    $partyKeys[$partyKey] = true;
                }
                $directions[$input->direction] = true;
                $currencies[$input->currency] = true;
            }
            $options[] = [
                'id' => $id,
                'name' => $subject === '' ? $number : $number.' — '.$subject,
                'project_ids' => array_keys($projectIds),
                'party_keys' => array_keys($partyKeys),
                'directions' => array_keys($directions),
                'currencies' => array_keys($currencies),
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<ContractSettlementInput>  $inputs
     * @return list<array{id:string,name:string,contract_ids:list<int>}>
     */
    private function partyOptions(array $inputs): array
    {
        $parties = [];
        foreach ($inputs as $input) {
            $partyKey = $input->partyKey();
            if ($partyKey === null) {
                continue;
            }

            if (! array_key_exists($partyKey, $parties)) {
                $parties[$partyKey] = ['names' => [], 'contract_ids' => []];
            }
            $parties[$partyKey]['names'][trim($input->partyLabel)] = true;
            $parties[$partyKey]['contract_ids'][$input->contractId] = true;
        }

        $options = [];
        foreach ($parties as $partyKey => $party) {
            $names = array_keys($party['names']);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $contractIds = array_keys($party['contract_ids']);
            sort($contractIds, SORT_NUMERIC);
            $options[] = [
                'id' => $partyKey,
                'name' => implode(' / ', $names),
                'contract_ids' => $contractIds,
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  array<int, ContractSettlementInput>  $inputs
     * @param  list<array{id:int,name:string}>  $projects
     * @param  list<array{id:int,name:string,project_ids:list<int>,party_keys:list<string>,directions:list<string>,currencies:list<string>}>  $contracts
     * @return list<array{id:int,name:string,contract_id:int,project_id:int,party_key:string|null,direction:string,currency:string}>
     */
    private function allocationOptions(array $inputs, array $projects, array $contracts): array
    {
        $projectNames = array_column($projects, 'name', 'id');
        $contractNames = array_column($contracts, 'name', 'id');
        $options = [];
        foreach ($inputs as $allocationId => $input) {
            $contract = $contractNames[$input->contractId] ?? null;
            $project = $input->projectId === null ? null : ($projectNames[$input->projectId] ?? null);
            if (! is_string($contract) || ! is_string($project)) {
                throw new DomainException('contract_settlement_options_reference_missing');
            }
            $options[] = [
                'id' => $allocationId,
                'name' => $contract.' — '.$project,
                'contract_id' => $input->contractId,
                'project_id' => $input->projectId,
                'party_key' => $input->partyKey(),
                'direction' => $input->direction,
                'currency' => $input->currency,
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return list<array{id:string,name:string,contract_ids:list<int>}>
     */
    private function paymentOptions(array $payloads, string $field): array
    {
        $values = [];
        foreach ($payloads as $payload) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $contractId = $payload['invoiceable_id'] ?? null;
                if (! is_int($contractId) && ! (is_string($contractId) && ctype_digit($contractId))) {
                    throw new DomainException('contract_settlement_options_payment_contract_invalid');
                }
                $values[$value][(int) $contractId] = true;
            }
        }

        $options = [];
        foreach ($values as $value => $contractIds) {
            $valid = $field === 'document_type'
                ? PaymentDocumentType::tryFrom($value) !== null
                : PaymentDocumentStatus::tryFrom($value) !== null;
            if (! $valid) {
                throw new DomainException('contract_settlement_options_payment_value_invalid');
            }
            $group = $field === 'document_type' ? 'instruments' : 'statuses';
            $options[] = [
                'id' => $value,
                'name' => trans_message('reports.options.contract_settlement_exposure.'.$group.'.'.$value),
                'contract_ids' => array_keys($contractIds),
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $values
     * @return list<array{id:string,name:string}>
     */
    private function translatedOptions(string $group, array $values): array
    {
        $options = array_map(static fn (string $value): array => [
            'id' => $value,
            'name' => trans_message('reports.options.contract_settlement_exposure.'.$group.'.'.$value),
        ], $values);

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{id:string,name:string}>
     */
    private function currencyOptions(array $codes): array
    {
        $labels = CurrencyCode::options();
        $options = [];
        foreach ($codes as $code) {
            if (! isset($labels[$code])) {
                throw new DomainException('contract_settlement_options_currency_invalid');
            }
            $options[] = ['id' => $code, 'name' => $labels[$code]];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $buckets
     * @return list<array{id:string,name:string}>
     */
    private function agingOptions(array $buckets): array
    {
        foreach ($buckets as $bucket) {
            if ($bucket !== 'due_date_missing' && SettlementAgingBucket::tryFrom($bucket) === null) {
                throw new DomainException('contract_settlement_options_aging_invalid');
            }
        }

        return $this->translatedOptions('aging_buckets', $buckets);
    }

    /** @param list<array{id:int|string,name:string}> $options */
    private function sorted(array $options): array
    {
        usort($options, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['name'],
            (string) $right['name'],
        ));

        return $options;
    }

    /** @return array<string, mixed> */
    private function unavailable(DateTimeImmutable $asOf, string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'as_of' => $this->exactDateTime($asOf),
            'period' => ['default_from' => null, 'default_to' => null],
            'due' => ['minimum' => null, 'maximum' => null],
            'projects' => [],
            'contracts' => [],
            'allocations' => [],
            'parties' => [],
            'directions' => [],
            'instruments' => [],
            'statuses' => [],
            'currencies' => [],
            'aging_buckets' => [],
        ];
    }

    private function exactDateTime(DateTimeImmutable $value): string
    {
        return $value->format('u') === '000000'
            ? $value->format(DATE_ATOM)
            : $value->format('Y-m-d\TH:i:s.uP');
    }
}
