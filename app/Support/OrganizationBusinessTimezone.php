<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organization;
use DateTimeZone;
use DomainException;

final readonly class OrganizationBusinessTimezone
{
    private const PRODUCT_DEFAULT = 'Europe/Moscow';

    public function resolve(Organization $organization): DateTimeZone
    {
        $settings = is_array($organization->multi_org_settings)
            ? $organization->multi_org_settings
            : [];
        $name = $settings['default_timezone'] ?? self::PRODUCT_DEFAULT;

        if (! is_string($name) || ! in_array($name, timezone_identifiers_list(), true)) {
            throw new DomainException(trans_message('errors.business_timezone_invalid'));
        }

        return new DateTimeZone($name);
    }
}
