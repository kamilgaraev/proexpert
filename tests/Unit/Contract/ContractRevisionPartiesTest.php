<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractRevisionParties;
use PHPUnit\Framework\TestCase;

final class ContractRevisionPartiesTest extends TestCase
{
    public function test_every_party_must_confirm_the_same_hash_with_its_own_identity(): void
    {
        $third = '11111111-1111-4111-8111-111111111111';
        $parties = [['side' => 'first', 'linked_organization_id' => 1], ['side' => 'second', 'linked_organization_id' => 2], ['side' => null, 'party_key' => $third, 'linked_organization_id' => null]];
        $confirmations = [['side' => 'first', 'content_hash' => 'hash', 'kind' => 'connected', 'party_organization_id' => 1], ['side' => 'second', 'content_hash' => 'hash', 'kind' => 'connected', 'party_organization_id' => 2]];
        self::assertFalse(ContractRevisionParties::confirmed($parties, $confirmations, 'hash'));
        $confirmations[] = ['party_key' => $third, 'content_hash' => 'hash', 'kind' => 'external', 'party_organization_id' => null];
        self::assertTrue(ContractRevisionParties::confirmed($parties, $confirmations, 'hash'));
        $wrongHash = $confirmations; $wrongHash[2]['content_hash'] = 'another';
        self::assertFalse(ContractRevisionParties::confirmed($parties, $wrongHash, 'hash'));
        $wrongIdentity = $confirmations; $wrongIdentity[1]['party_organization_id'] = 3;
        self::assertFalse(ContractRevisionParties::confirmed($parties, $wrongIdentity, 'hash'));
        self::assertFalse(ContractRevisionParties::confirmed($parties, [...$confirmations, $confirmations[2]], 'hash'));
        $unknownParty = $confirmations; $unknownParty[2]['party_key'] = '22222222-2222-4222-8222-222222222222';
        self::assertFalse(ContractRevisionParties::confirmed($parties, $unknownParty, 'hash'));
        self::assertTrue(ContractRevisionParties::confirmed(array_slice($parties, 0, 2), array_slice($confirmations, 0, 2), 'hash'));
    }
}
