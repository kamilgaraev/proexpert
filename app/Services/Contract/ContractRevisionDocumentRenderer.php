<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractRevisionDocumentRenderer
{
    public function profileMetadata(array $revision, ?array $terms = null): array
    {
        if (($revision['contract_profile_code'] ?? null) !== 'contract.supply') return [];
        $terms ??= (new ContractRevisionTermsCompiler)->compile($revision)['terms'];
        $parties = array_column($revision['parties'], null, 'side');
        return array_filter([
            'subject' => $terms['subject'] ?? null,
            'buyer' => ($parties['first']['legal_name'] ?? '') ?: ($parties['first']['name'] ?? null),
            'supplier' => ($parties['second']['legal_name'] ?? '') ?: ($parties['second']['name'] ?? null),
            'price' => $terms['total_amount'] ?? null,
            'delivery_terms' => $terms['delivery_terms'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && (!is_string($value) || trim($value) !== ''));
    }

    public function render(object $revision): string
    {
        $decode = static fn (string $value): array => json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return $this->renderData([
            'document' => $decode($revision->document), 'definitions' => $decode($revision->definitions),
            'values' => $decode($revision->values), 'entity_snapshots' => $decode($revision->entity_snapshots),
            'parties' => $decode($revision->parties), 'attachments' => $decode($revision->attachments),
            'revision_number' => $revision->revision_number, 'content_hash' => $revision->content_hash,
        ]);
    }

    public function renderData(array $revision): string
    {
        $html = (new ContractDocumentRenderer)->render($revision['document'], $revision['definitions'], $revision['values'], $revision['entity_snapshots']);
        $manifest = '<h2>'.e(trans_message('contract_templates.document_manifest')).'</h2><p>'.e(trans_message('contract_templates.document_revision', ['number' => $revision['revision_number']])).'</p><p>'.e($revision['content_hash']).'</p>';
        foreach ($revision['parties'] as $party) {
            $manifest .= '<h3>'.e(($party['side'] ?? null) === null ? ($party['role_label'] ?? 'Участник договора') : trans_message('contract_templates.document_party_'.$party['side'])).'</h3>';
            $manifest .= '<p>'.e(($party['legal_name'] ?? '') ?: ($party['name'] ?? '')).'</p>';
            foreach (['inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'] as $field) {
                if (isset($party[$field]) && $party[$field] !== '') {
                    $manifest .= '<p>'.e(trans_message('contract_templates.document_party_fields.'.$field)).': '.e($party[$field]).'</p>';
                }
            }
            foreach (ContractPartyRequisites::LABELS as $field => $label) {
                if (isset($party[$field]) && $party[$field] !== '') {
                    $manifest .= '<p>'.e($label).': '.e(ContractPartyRequisites::display($field, $party[$field])).'</p>';
                }
            }
        }
        foreach ($revision['attachments'] as $asset) {
            $manifest .= '<p>'.e($asset['name']).' — '.e($asset['sha256']).'</p>';
        }
        $layout = new ContractDocumentPrintLayout;
        $plan = $layout->decode($html);
        if ($plan === null) {
            return $html.$manifest;
        }
        $width = $plan['width'] / ContractDocumentPrintMeasure::PT_PER_MM - $plan['page']['margins']['left'] - $plan['page']['margins']['right'];
        $extra = $layout->decode($layout->render(['version' => 1, 'page' => $plan['page'], 'grid' => ['size' => 5, 'snap' => true, 'visible' => true]], [
            ['layout' => ['id' => 'revision-manifest', 'x' => 0, 'y' => 0, 'width' => $width, 'minHeight' => 0], 'html' => $manifest],
        ]));
        $plan['pages'] = [...$plan['pages'], ...$extra['pages']];
        (new ContractPrintPlanValidator)->validate($plan);

        return $layout->html($plan);
    }
}
