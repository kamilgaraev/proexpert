<?php

declare(strict_types=1);

namespace App\DTOs\Contract;

final readonly class ContractRegistrationNumber
{
    public ?string $kpp;

    public ?string $ogrn;

    public function __construct(?string $value)
    {
        $digits = $value === null ? '' : (preg_replace('/\D+/', '', $value) ?? '');

        $this->kpp = strlen($digits) === 9 ? $digits : null;
        $this->ogrn = in_array(strlen($digits), [13, 15], true) ? $digits : null;
    }
}
