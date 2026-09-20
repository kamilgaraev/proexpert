<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

use function trans_message;

final class ContractStandardTemplates
{
    public const VERSION = 1;
    public const PREFIX = 'most-standard:';
    public const CODES = ['work', 'construction', 'subcontract'];

    public function catalogue(): array
    {
        return array_map(fn (string $code): array => [
            'code' => $code, 'version' => self::VERSION, 'title' => trans_message('contract_templates.'.$code.'.title'),
            'description' => trans_message('contract_templates.'.$code.'.description'), 'contract_profile_code' => 'contract.'.$code,
        ], self::CODES);
    }

    public function fields(string $code): array
    {
        $this->assertCode($code);
        $fields = [];
        foreach (['number' => 'contract.number', 'date' => 'contract.date', 'project' => 'project.name',
            'payer' => 'first_party.name', 'executor' => 'second_party.name',
            'payer_inn' => 'first_party.inn', 'executor_inn' => 'second_party.inn'] as $key => $source) {
            $fields[$key] = ['type' => $key === 'date' ? 'date' : 'text', 'required' => !str_ends_with($key, '_inn'),
                'source' => ['kind' => 'contract_context', 'field' => $source],
                'display' => ['group' => trans_message('contract_templates.groups.context')]];
        }
        $fields['subject'] = ['type' => 'text', 'required' => true, 'constraints' => ['max_length' => 10000], 'assignment' => ['target' => 'subject']];
        $fields['price'] = ['type' => 'money', 'required' => true, 'constraints' => ['currency' => 'RUB', 'min' => '0'], 'assignment' => ['target' => 'price']];
        foreach (['start_date', 'end_date'] as $key) {
            $fields[$key] = ['type' => 'date', 'required' => true, 'assignment' => ['target' => 'schedule', 'field' => $key]];
        }
        foreach (['payment_terms', 'acceptance', 'responsibility'] as $key) {
            $fields[$key] = ['type' => 'text', 'required' => true, 'constraints' => ['max_length' => 10000]];
        }
        $fields['payment_terms']['assignment'] = ['target' => 'payment_terms'];
        if ($code === 'construction') {
            $fields['technical_documents'] = ['type' => 'text', 'required' => true, 'constraints' => ['max_length' => 10000]];
        }
        if ($code === 'subcontract') {
            $fields['coordination'] = ['type' => 'text', 'required' => true, 'constraints' => ['max_length' => 10000]];
        }
        foreach ($fields as $key => &$definition) {
            $definition['display'] ??= ['group' => trans_message('contract_templates.groups.conditions'),
                'hint' => trans_message('contract_templates.hints.'.$key)];
        }
        unset($definition);

        return $fields;
    }

    public function content(string $code, array $ids): array
    {
        $this->assertCode($code);
        $text = static fn (string $value): array => ['type' => 'text', 'text' => $value];
        $field = static fn (string $key): array => ['type' => 'variable', 'attrs' => ['variableId' => $ids[$key]]];
        $paragraph = static fn (array $nodes): array => ['type' => 'paragraph', 'content' => $nodes];
        $clause = static fn (string $id, array $nodes): array => ['type' => 'clause', 'attrs' => ['id' => $id], 'content' => $nodes];
        $nodes = [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [$text(trans_message('contract_templates.'.$code.'.title')), $text(' № '), $field('number')]],
            $paragraph([$text(trans_message('contract_templates.labels.date').': '), $field('date')]),
            $clause('parties', [$paragraph([$text(trans_message('contract_templates.party_intro')), $field('payer'),
                $text(trans_message('contract_templates.party_between')), $field('executor'), $text(trans_message('contract_templates.party_outro'))]),
                $paragraph([$text(trans_message('contract_templates.labels.project').': '), $field('project')])]),
        ];
        $keys = ['subject', 'price', 'start_date', 'end_date', 'payment_terms'];
        if ($code === 'construction') {
            $keys[] = 'technical_documents';
        }
        if ($code === 'subcontract') {
            $keys[] = 'coordination';
        }
        foreach ([...$keys, 'acceptance', 'responsibility'] as $key) {
            $nodes[] = $clause($key, [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$text(trans_message('contract_templates.labels.'.$key))]],
                $paragraph([$text(trans_message('contract_templates.clause_intro.'.$key)), $field($key)]),
            ]);
        }
        $nodes[] = $clause('signatures', [
            $paragraph([$text(trans_message('contract_templates.payer_signature')), $field('payer'), $text(trans_message('contract_templates.inn_separator')), $field('payer_inn')]),
            $paragraph([$text(trans_message('contract_templates.executor_signature')), $field('executor'), $text(trans_message('contract_templates.inn_separator')), $field('executor_inn')]),
        ]);

        return ['contract_profile_code' => 'contract.'.$code, 'document' => ['type' => 'doc', 'content' => $nodes],
            'variables' => array_fill_keys(array_values($ids), self::VERSION)];
    }

    private function assertCode(string $code): void
    {
        if (!in_array($code, self::CODES, true)) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }
    }
}
