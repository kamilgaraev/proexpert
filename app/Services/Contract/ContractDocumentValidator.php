<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Illuminate\Support\Str;

final class ContractDocumentValidator
{
    private string $path = 'document';
    private int $imageBytes = 0;
    private int $imageCount = 0;

    public function validate(array $content, ?array $definitions = null, bool $deferReferences = false): void
    {
        $this->path = 'document';
        $this->imageBytes = 0;
        $this->imageCount = 0;
        if (array_diff(array_keys($content), ['document', 'variables', 'contract_profile_code']) !== []
            || ! is_array($content['document'] ?? null) || ($content['document']['type'] ?? null) !== 'doc'
            || ! is_array($content['variables'] ?? null) || count($content['variables']) > 500) {
            $this->invalid();
        }
        if (array_key_exists('contract_profile_code', $content)
            && (!is_string($content['contract_profile_code']) || !array_key_exists($content['contract_profile_code'], ContractDocumentProfiles::all()))) {
            $this->invalid();
        }
        foreach ($content['variables'] as $id => $version) {
            if (! is_string($id) || ! Str::isUuid($id) || ! is_int($version) || $version < 1) {
                $this->invalid();
            }
        }
        $count = 0;
        $clauses = [];
        $references = [];
        $this->node($content['document'], ['doc'], $content['variables'], $definitions, null, 0, $count, $clauses, $references);
        (new ContractDocumentLayoutValidator)->validate($content['document']);
        if (! $deferReferences && array_diff(array_keys($references), array_keys($clauses)) !== []) {
            $missing = array_values(array_diff(array_keys($references), array_keys($clauses)))[0];
            $this->path = $references[$missing];
            $this->invalid(trans_message('contracts.document_link_target_missing'));
        }
    }

    private function node(array $node, array $allowed, array $variables, ?array $definitions, ?string $rowVariable, int $depth, int &$count, array &$clauses, array &$references, string $path = 'document'): void
    {
        $this->path = $path;
        if ($depth > 32 || ++$count > 20000 || ! is_string($node['type'] ?? null) || ! in_array($node['type'], $allowed, true)) {
            $this->invalid();
        }
        $type = $node['type'];
        $keys = $type === 'text' ? ['type', 'text', 'marks'] : ['type', 'attrs', 'content'];
        if (array_diff(array_keys($node), $keys) !== []) {
            $this->invalid();
        }
        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || mb_strlen($node['text']) > 100000) {
                $this->invalid();
            }
            $this->marks($node['marks'] ?? []);

            return;
        }
        $attrs = $node['attrs'] ?? [];
        if (! is_array($attrs)) {
            $this->invalid();
        }
        $attributeKeys = match ($type) {
            'doc' => ['pageDecorations', 'locale'],
            'image' => ['src', 'alt', 'width', 'height'],
            'heading' => ['level'], 'orderedList' => ['start'], 'clause' => ['id', 'textPlacement'],
            'clauseReference' => ['target'], 'variable' => ['variableId', 'columnId'],
            'conditional', 'repeatRows' => ['variableId'], 'tableCell' => ['colspan', 'rowspan'],
            'table' => ['columnWidths'], 'tableRow' => ['header'],
            'blockReference' => ['blockId', 'version', 'instanceId'],
            default => [],
        };
        if ($type === 'doc' && array_key_exists('locale', $attrs)) { ContractDocumentLocale::validate($attrs['locale']); }
        if ($type === 'doc' && array_key_exists('pageDecorations', $attrs)) {
            if (array_key_exists('layout', $attrs)) {
                try { (new ContractDocumentLayoutValidator)->page($attrs['layout']); }
                catch (ContractBuilderException $error) { throw $error->atField('document.attrs.layout'); }
            }
            (new ContractPageDecorations)->validate($attrs['pageDecorations'], $attrs['layout']['page'] ?? ContractPageDecorations::defaultLayout()['page']);
        }
        if (in_array($type, ['doc', 'paragraph', 'heading', 'clause', 'group', 'conditional', 'table', 'orderedList', 'bulletList', 'blockReference', 'image'], true)) {
            $attributeKeys[] = 'layout';
        }
        if (in_array($type, ['paragraph', 'heading'], true)) {
            $attributeKeys[] = 'format';
            if (array_key_exists('format', $attrs)) {
                try {
                    ContractTypography::validate($attrs['format'], true);
                } catch (ContractBuilderException $error) {
                    throw $error->atField($path.'.attrs.format');
                }
            }
        }
        if (array_diff(array_keys($attrs), $attributeKeys) !== []) {
            $this->invalid();
        }
        if ($type === 'image') {
            try {
                $this->imageBytes += ContractInlineImage::attributes($attrs);
                if (++$this->imageCount > 20 || $this->imageBytes > 2 * 1024 * 1024) { throw new ContractBuilderException('contracts.builder_image_invalid', 422); }
            } catch (ContractBuilderException $error) { throw $error->atField($path.'.attrs'); }
        }
        if ($type === 'blockReference' && (! is_string($attrs['blockId'] ?? null) || ! Str::isUuid($attrs['blockId'])
            || ! is_int($attrs['version'] ?? null) || $attrs['version'] < 1
            || ! is_string($attrs['instanceId'] ?? null) || preg_match('/^[A-Za-z0-9-]{1,24}$/D', $attrs['instanceId']) !== 1)) {
            $this->invalid();
        }
        if ($type === 'heading' && (! is_int($attrs['level'] ?? null) || $attrs['level'] < 1 || $attrs['level'] > 6)) {
            $this->invalid();
        }
        if ($type === 'clause' && array_key_exists('textPlacement', $attrs)
            && ! in_array($attrs['textPlacement'], ['inline', 'new_line'], true)) {
            $this->invalid();
        }
        foreach (['start', 'colspan', 'rowspan'] as $positive) {
            if (array_key_exists($positive, $attrs) && (! is_int($attrs[$positive]) || $attrs[$positive] < 1 || $attrs[$positive] > 1000)) {
                $this->invalid();
            }
        }
        if ($type === 'clause' || $type === 'clauseReference') {
            $id = $attrs[$type === 'clause' ? 'id' : 'target'] ?? null;
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $id) !== 1) {
                $this->invalid();
            }
            if ($type === 'clause') {
                if (isset($clauses[$id])) {
                    $this->invalid(trans_message('contracts.document_clause_duplicate'));
                }
                $clauses[$id] = true;
            } else {
                $references[$id] = $path;
            }
        }
        if (in_array($type, ['variable', 'conditional', 'repeatRows'], true)) {
            $id = $attrs['variableId'] ?? null;
            if (! is_string($id) || ! array_key_exists($id, $variables)) {
                $this->invalid(trans_message('contracts.document_field_unbound'));
            }
            $definition = $definitions[$id] ?? null;
            if ($definitions !== null && ! is_array($definition)) {
                $this->invalid(trans_message('contracts.document_field_unavailable'));
            }
            if ($type === 'conditional' && $definition !== null && ($definition['type'] ?? null) !== 'boolean') {
                $this->invalid(trans_message('contracts.document_condition_boolean_required'));
            }
            if ($type === 'repeatRows') {
                if ($rowVariable !== null || ($definition !== null && ($definition['type'] ?? null) !== 'table')) {
                    $this->invalid(trans_message('contracts.document_repeat_table_required'));
                }
                $rowVariable = $id;
            }
            if (array_key_exists('columnId', $attrs)) {
                if ($rowVariable !== $id || ! is_string($attrs['columnId'])
                    || ($definition !== null && ! in_array($attrs['columnId'], array_column($definition['columns'] ?? [], 'id'), true))) {
                    $this->invalid(trans_message('contracts.document_column_unbound'));
                }
            }
        }
        $block = ['paragraph', 'heading', 'clause', 'group', 'conditional', 'table', 'orderedList', 'bulletList', 'blockReference', 'image'];
        $inline = ['text', 'variable', 'clauseReference', 'hardBreak'];
        $childrenAllowed = match ($type) {
            'doc', 'clause', 'group', 'conditional', 'listItem', 'tableCell' => $block,
            'paragraph', 'heading' => $inline,
            'orderedList', 'bulletList' => ['listItem'],
            'table' => ['tableRow', 'repeatRows'], 'repeatRows' => ['tableRow'], 'tableRow' => ['tableCell'],
            default => [],
        };
        $children = $node['content'] ?? [];
        if (! is_array($children) || ! array_is_list($children) || ($childrenAllowed === [] && $children !== [])
            || (in_array($type, ['table', 'repeatRows', 'orderedList', 'bulletList'], true) && $children === [])) {
            $this->invalid();
        }
        foreach ($children as $index => $child) {
            if (! is_array($child)) {
                $this->invalid();
            }
            $this->node($child, $childrenAllowed, $variables, $definitions, $rowVariable, $depth + 1, $count, $clauses, $references, $path.'.content.'.$index);
        }
        if ($type === 'table') {
            (new ContractTableGridValidator)->validate($node, $path);
        }
    }

    private function marks(mixed $marks): void
    {
        if (! is_array($marks) || ! array_is_list($marks) || count($marks) > 8) {
            $this->invalid();
        }
        $seen = [];
        foreach ($marks as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null)
                || ! in_array($mark['type'], ['bold', 'italic', 'underline', 'strike', 'link', 'textStyle', 'superscript', 'subscript'], true)
                || isset($seen[$mark['type']]) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
                $this->invalid();
            }
            $seen[$mark['type']] = true;
            $attrs = $mark['attrs'] ?? [];
            if (! is_array($attrs)) {
                $this->invalid();
            }
            if ($mark['type'] === 'link') {
                $href = $attrs['href'] ?? null;
                if (array_keys($attrs) !== ['href'] || ! is_string($href) || strlen($href) > 2048
                    || preg_match('/[\x00-\x20\x7f]/', $href) === 1
                    || ! in_array(strtolower((string) parse_url($href, PHP_URL_SCHEME)), ['https', 'http', 'mailto'], true)) {
                    $this->invalid();
                }
            } elseif ($mark['type'] === 'textStyle') {
                try {
                    ContractTypography::validate($attrs);
                } catch (ContractBuilderException $error) {
                    throw $error->atField($this->path.'.marks');
                }
            } elseif ($attrs !== []) {
                $this->invalid();
            }
        }
        if (isset($seen['superscript'], $seen['subscript'])) {
            $this->invalid();
        }
    }

    private function invalid(?string $reason = null): never
    {
        $positions = [];
        preg_match_all('/content\.(\d+)/', $this->path, $matches);
        foreach ($matches[1] as $position) $positions[] = (int) $position + 1;
        $reason ??= trans_message('contracts.document_node_reason_invalid');
        $location = $positions === [] ? trans_message('contracts.document_location') : trans_message('contracts.document_block_location', ['number' => implode('.', $positions)]);
        throw new ContractBuilderException('contracts.builder_document_node_invalid', 422, ['location' => $location, 'reason' => $reason], $this->path);
    }
}
