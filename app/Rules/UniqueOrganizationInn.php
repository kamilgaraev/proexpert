<?php

namespace App\Rules;

use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Проверка уникальности ИНН организации
 * 
 * Проверяет что ИНН (tax_number) не используется другой организацией
 */
class UniqueOrganizationInn implements ValidationRule
{
    private ?int $exceptOrganizationId = null;

    /**
     * @param int|null $exceptOrganizationId ID организации которую нужно исключить из проверки (для update)
     */
    public function __construct(?int $exceptOrganizationId = null)
    {
        $this->exceptOrganizationId = $exceptOrganizationId;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Если ИНН пустой, пропускаем проверку (пусть required/nullable решает)
        if (empty($value)) {
            return;
        }

        $inn = trim((string)$value);

        // Исключаем ИНН помеченные как дубликаты (с суффиксом -DUP-)
        if (str_contains($inn, '-DUP-')) {
            $fail('ИНН не может содержать служебные метки.');
            return;
        }

        // Проверяем существование организации с таким ИНН
        $query = Organization::where('tax_number', $inn);

        // Исключаем текущую организацию (для update)
        if ($this->exceptOrganizationId) {
            $query->where('id', '!=', $this->exceptOrganizationId);
        }

        $existingOrganization = $query->first();

        if ($existingOrganization) {
            $fail("Организация с ИНН {$inn} уже зарегистрирована в системе.");
            return;
        }
    }
}

