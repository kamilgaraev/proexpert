<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Services\Contract\ContractDossierCounterpartyResolver;
use App\Services\Contract\ContractSideResolverService;
use App\Services\Contract\ContractPartyConsistencyReport;
use App\Services\Project\ProjectCustomerResolverService;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Mockery;

final class ContractPerspectiveTest extends TestCase
{
    public static function paymentCurrencies(): array
    {
        return [
            'omitted' => [[], 'USD'],
            'null' => [['currency' => null], 'USD'],
            'empty' => [['currency' => ''], 'USD'],
            'explicit' => [['currency' => 'EUR'], 'EUR'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentCurrencies')]
    public function test_same_saved_parties_have_opposite_directions_and_counterparties(array $currencyInput, string $expectedCurrency): void
    {
        $contract = new Contract;
        $contract->setRawAttributes(['id' => 3, 'organization_id' => 20, 'contract_side_type' => 'subcontract', 'currency' => 'USD']);
        $first = new ContractParty;
        $first->setRawAttributes(['id' => 1, 'linked_organization_id' => 10, 'name' => 'Заказчик работ', 'role' => 'contractor']);
        $second = new ContractParty;
        $second->setRawAttributes(['id' => 2, 'linked_organization_id' => 20, 'name' => 'Исполнитель работ', 'role' => 'subcontractor']);
        $contract->setRelation('firstParty', $first);
        $contract->setRelation('secondParty', $second);
        $contract->setRelation('contractor', null);
        $resolver = new ContractSideResolverService(new ProjectCustomerResolverService);
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance(ContractSideResolverService::class, $resolver);
        Container::setInstance($container);

        try {
            self::assertSame('income', $resolver->resolve($contract, 20)['direction']);
            self::assertSame('expense', $resolver->resolve($contract, 10)['direction']);
            $dossier = new ContractDossierCounterpartyResolver;
            self::assertSame('Заказчик работ', $dossier->name($contract));
            self::assertSame('Исполнитель работ', $dossier->name($contract, 10));
            self::assertFalse($contract->relationLoaded('project'));
            $payment = new PaymentDocument;
            $payments = Mockery::mock(PaymentDocumentService::class)->makePartial();
            $payments->shouldReceive('create')->twice()->withArgs(static function (array $data) use ($expectedCurrency): bool {
                self::assertSame(10, $data['payer_organization_id']);
                self::assertSame(20, $data['payee_organization_id']);
                self::assertSame(InvoiceDirection::INCOMING, $data['direction']);
                self::assertSame($expectedCurrency, $data['currency']);
                self::assertSame(20, $data['organization_id']);

                return true;
            })->andReturn($payment);
            $payments->shouldReceive('detectAndSetRecipientOrganization')->once()->with($payment);
            $payments->createFromContract($contract, InvoiceType::cases()[0], [...$currencyInput, 'payer_organization_id' => 20, 'organization_id' => 99]);
            $act = new \App\Models\ContractPerformanceAct;
            $act->setRawAttributes(['id' => 7, 'act_date' => '2026-09-19', 'currency' => $currencyInput['currency'] ?? null, 'amount' => '100']);
            $act->setRelation('contract', $contract);
            $payments->createFromAct($act, InvoiceDirection::OUTGOING);
            $report = new ContractPartyConsistencyReport;
            self::assertSame([], $report->issues($contract));
            $second->linked_organization_id = 10;
            self::assertSame(['identical_organizations', 'owner_not_linked_to_parties'], $report->issues($contract));
        } finally {
            Mockery::close();
            Container::setInstance($previous);
        }
    }
}
