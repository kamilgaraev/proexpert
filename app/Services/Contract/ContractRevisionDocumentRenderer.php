<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractRevisionDocumentRenderer
{
    public function render(object $revision): string
    {
        $decode = static fn (string $value): array => json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        $html = (new ContractDocumentRenderer)->render($decode($revision->document), $decode($revision->definitions), $decode($revision->values), $decode($revision->entity_snapshots));
        $manifest = '<h2>'.e(trans_message('contract_templates.document_manifest')).'</h2><p>'.e(trans_message('contract_templates.document_revision', ['number' => $revision->revision_number])).'</p><p>'.e($revision->content_hash).'</p>';
        foreach ($decode($revision->parties) as $party) {
            $manifest .= '<h3>'.e(trans_message('contract_templates.document_party_'.$party['side'])).'</h3>';
            $manifest .= '<p>'.e(($party['legal_name'] ?? '') ?: ($party['name'] ?? '')).'</p>';
            foreach (['inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'] as $field) {
                if (isset($party[$field]) && $party[$field] !== '') {
                    $manifest .= '<p>'.e(trans_message('contract_templates.document_party_fields.'.$field)).': '.e($party[$field]).'</p>';
                }
            }
        }
        foreach ($decode($revision->attachments) as $asset) {
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
