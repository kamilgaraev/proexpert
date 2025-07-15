<?php

namespace App\Rules;

use App\Models\Contract;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;

class ParentContractValid implements InvokableRule, DataAwareRule
{
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function __invoke(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value) {
            return; // пусто — ок
        }

        $parent = Contract::find($value);
        if (!$parent) {
            $fail('Выбранный родительский контракт не найден.');
            return;
        }

        // Определяем организацию создаваемого/обновляемого контракта
        $organizationId = $this->data['organization_id_for_creation'] ?? null; // при create
        $organizationId ??= $this->data['organization_id'] ?? null; // при update может прийти из route/service

        if ($organizationId && $parent->organization_id !== $organizationId) {
            $fail('Родительский контракт принадлежит другой организации.');
            return;
        }

        // Проверяем цикл (parent == self или выше по цепочке)
        $currentId = $this->data['id'] ?? null; // при update в rules можно передать
        $ancestor = $parent;
        while ($ancestor) {
            if ($ancestor->id === $currentId) {
                $fail('Циклическая ссылка на родительский контракт.');
                return;
            }
            $ancestor = $ancestor->parentContract; // relation
        }
    }
} 