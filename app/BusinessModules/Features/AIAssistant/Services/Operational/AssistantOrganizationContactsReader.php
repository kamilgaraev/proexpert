<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationContactsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_contacts_projection';

    public static function hasIntent(string $query): bool
    {
        $query = preg_replace('/без\s+(?:телефон[а-яё]*\s+и\s+почт[а-яё]*|контакт[а-яё]*)/iu', '', $query) ?? $query;
        if (preg_match('/уведомлен/iu', $query) === 1 && preg_match('/адрес|телефон|контакт/iu', $query) !== 1) { return false; }
        return preg_match('/организац[\s\S]*(?:адрес|телефон|почт|контакт)|(?:адрес|телефон|почт|контакт)[\s\S]*организац/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/измени|обнови|запиши|удали|отправь\s+документ|\bправ[а-яё]*|сотрудник|пользовател|поставщик|подрядчик|реквизит[а-яё]*\s+банк|сч[её]т|ндс|валют|парол|ключ/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! User::query()->whereKey($actor->id)->whereNotNull('email_verified_at')->exists()
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'organization.manage')) { throw new AuthorizationException; }
        $organization = Organization::query()->whereKey($organizationId)->first(['id', 'name', 'phone', 'email', 'address', 'city', 'postal_code', 'country']);
        if ($organization === null) { throw new AuthorizationException; }

        return ['name' => $organization->name, 'phone' => $organization->phone, 'email' => $organization->email,
            'address' => $organization->address, 'city' => $organization->city, 'postal_code' => $organization->postal_code, 'country' => $organization->country];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.organization_contacts_scope', ['name' => self::text($data['name']), 'date' => $data['as_of_date']])];
        foreach (['address', 'city', 'postal_code', 'country', 'phone', 'email'] as $field) {
            $value = is_string($data[$field]) && trim($data[$field]) !== '' ? self::text($data[$field]) : trans_message('ai_assistant_operational.not_recorded');
            $lines[] = trans_message('ai_assistant_operational.organization_contact_'.$field, ['value' => $value]);
        }
        $lines[] = trans_message('ai_assistant_operational.organization_contacts_basis');

        return implode("\n", $lines);
    }
}
