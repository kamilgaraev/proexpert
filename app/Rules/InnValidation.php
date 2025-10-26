<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Валидация ИНН (Индивидуальный Налоговый Номер)
 * 
 * Проверяет:
 * - Длину (10 или 12 символов)
 * - Что содержит только цифры
 * - Контрольную сумму по алгоритму ФНС РФ
 */
class InnValidation implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Проверка что значение не пустое
        if (empty($value)) {
            $fail('Поле :attribute обязательно для заполнения.');
            return;
        }

        // Приводим к строке и удаляем пробелы
        $inn = trim((string)$value);

        // Проверка длины
        $length = strlen($inn);
        if ($length !== 10 && $length !== 12) {
            $fail('ИНН должен содержать 10 цифр (для юридических лиц) или 12 цифр (для индивидуальных предпринимателей).');
            return;
        }

        // Проверка что содержит только цифры
        if (!preg_match('/^\d+$/', $inn)) {
            $fail('ИНН должен содержать только цифры.');
            return;
        }

        // Проверка контрольной суммы
        if (!$this->validateChecksum($inn)) {
            $fail('ИНН имеет неверную контрольную сумму. Проверьте правильность ввода.');
            return;
        }
    }

    /**
     * Проверка контрольной суммы ИНН по алгоритму ФНС РФ
     */
    private function validateChecksum(string $inn): bool
    {
        $length = strlen($inn);

        if ($length === 10) {
            return $this->validate10DigitInn($inn);
        } elseif ($length === 12) {
            return $this->validate12DigitInn($inn);
        }

        return false;
    }

    /**
     * Валидация 10-значного ИНН (для юридических лиц)
     * 
     * Контрольная сумма: последняя цифра
     */
    private function validate10DigitInn(string $inn): bool
    {
        $coefficients = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $checksum = 0;

        for ($i = 0; $i < 9; $i++) {
            $checksum += (int)$inn[$i] * $coefficients[$i];
        }

        $controlNumber = ($checksum % 11) % 10;

        return (int)$inn[9] === $controlNumber;
    }

    /**
     * Валидация 12-значного ИНН (для индивидуальных предпринимателей)
     * 
     * Две контрольные суммы: 11-я и 12-я цифры
     */
    private function validate12DigitInn(string $inn): bool
    {
        // Первая контрольная сумма (11-я цифра)
        $coefficients1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $checksum1 = 0;

        for ($i = 0; $i < 10; $i++) {
            $checksum1 += (int)$inn[$i] * $coefficients1[$i];
        }

        $controlNumber1 = ($checksum1 % 11) % 10;

        if ((int)$inn[10] !== $controlNumber1) {
            return false;
        }

        // Вторая контрольная сумма (12-я цифра)
        $coefficients2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $checksum2 = 0;

        for ($i = 0; $i < 11; $i++) {
            $checksum2 += (int)$inn[$i] * $coefficients2[$i];
        }

        $controlNumber2 = ($checksum2 % 11) % 10;

        return (int)$inn[11] === $controlNumber2;
    }
}

