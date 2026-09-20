<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Closure;

final class ContractDocumentResolver
{
    public function resolve(array $content, Closure $publishedVersion): array
    {
        $validator = new ContractDocumentValidator;
        $variables = [];
        $blocks = [];
        $cache = [];
        $nodes = 0;
        $expand = function (array $source, array $path, array $stack) use (&$expand, &$variables, &$blocks, &$cache, &$nodes, $validator, $publishedVersion): array {
            if (count($path) > 8) {
                $this->invalid();
            }
            $validator->validate($source, null, true);
            foreach ($source['variables'] as $id => $number) {
                if (isset($variables[$id]) && $variables[$id] !== $number) {
                    $this->invalid();
                }
                $variables[$id] = $number;
            }
            $localClauses = [];
            $collect = function (array $node) use (&$collect, &$localClauses): void {
                if ($node['type'] === 'clause') {
                    $localClauses[$node['attrs']['id']] = true;
                }
                foreach ($node['content'] ?? [] as $child) {
                    $collect($child);
                }
            };
            $collect($source['document']);
            $prefix = $path === [] ? '' : implode('__', $path).'__';
            $walk = function (array $node) use (&$walk, &$expand, &$blocks, &$cache, &$nodes, $publishedVersion, $path, $stack, $prefix, $localClauses): array {
                if (++$nodes > 20000) {
                    $this->invalid();
                }
                if ($node['type'] === 'blockReference') {
                    $attrs = $node['attrs'];
                    $key = $attrs['blockId'].'@'.$attrs['version'];
                    $nextPath = [...$path, $attrs['instanceId']];
                    $occurrence = implode('__', $nextPath);
                    if (isset($blocks[$occurrence]) || in_array($key, $stack, true) || count($blocks) >= 200) {
                        $this->invalid();
                    }
                    $version = $cache[$key] ??= $publishedVersion($attrs['blockId'], $attrs['version'], 'block');
                    $blocks[$occurrence] = [
                        'id' => $attrs['blockId'], 'version' => $attrs['version'], 'version_id' => $version['id'],
                        'title' => $version['title'], 'content' => $version['content'],
                    ];

                    $children = $expand($version['content'], $nextPath, [...$stack, $key]);
                    if ($prefix !== '' && isset($attrs['layout']['id'])) {
                        $attrs['layout']['id'] = $prefix.$attrs['layout']['id'];
                    }

                    return isset($attrs['layout'])
                        ? [['type' => 'group', 'attrs' => ['layout' => $attrs['layout']], 'content' => $children]]
                        : $children;
                }
                if ($prefix !== '' && isset($node['attrs']['layout']['id'])) {
                    $node['attrs']['layout']['id'] = $prefix.$node['attrs']['layout']['id'];
                }
                if ($prefix !== '' && $node['type'] === 'clause') {
                    $node['attrs']['id'] = $prefix.$node['attrs']['id'];
                }
                if ($prefix !== '' && $node['type'] === 'clauseReference' && isset($localClauses[$node['attrs']['target']])) {
                    $node['attrs']['target'] = $prefix.$node['attrs']['target'];
                }
                if (isset($node['content'])) {
                    $children = [];
                    foreach ($node['content'] as $child) {
                        array_push($children, ...$walk($child));
                    }
                    $node['content'] = $children;
                }

                return [$node];
            };
            $document = $walk($source['document'])[0];

            return $document['content'] ?? [];
        };
        $document = [...$content['document'], 'content' => $expand($content, [], [])];
        $definitions = [];
        foreach ($variables as $id => $number) {
            $version = $publishedVersion($id, $number, 'variable');
            $definitions[$id] = [
                'id' => $id, 'version' => $number, 'version_id' => $version['id'],
                'title' => $version['title'], 'definition' => $version['content'],
            ];
        }
        $validator->validate(['document' => $document, 'variables' => $variables], array_column($definitions, 'definition', 'id'));
        (new ContractFormulaEngine)->validate(array_column($definitions, 'definition', 'id'));

        return ['document' => $document, 'definitions' => $definitions, 'blocks' => $blocks,
            ...(isset($content['contract_profile_code']) ? ['contract_profile_code' => $content['contract_profile_code']] : [])];
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.builder_document_invalid', 422);
    }
}
