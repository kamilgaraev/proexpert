<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractFormattingDiff
{
    private const LABELS = ['locale' => 'Язык дат и чисел', 'layout' => 'Размещение и параметры листа', 'format' => 'Оформление абзаца', 'marks' => 'Оформление текста',
        'pageDecorations' => 'Колонтитулы и номера страниц', 'fontFamily' => 'Шрифт', 'fontSize' => 'Размер, пт', 'color' => 'Цвет', 'align' => 'Выравнивание',
        'lineHeight' => 'Межстрочный интервал', 'firstLineIndent' => 'Отступ первой строки, мм', 'spaceAfter' => 'Интервал после, мм', 'x' => 'Слева, мм', 'y' => 'Сверху, мм',
        'width' => 'Ширина, мм', 'minHeight' => 'Минимальная высота, мм', 'keepWithNext' => 'Не отрывать от следующего блока', 'breakBefore' => 'Разрыв перед блоком',
        'page' => 'Лист', 'orientation' => 'Ориентация', 'margins' => 'Поля, мм', 'top' => 'Сверху', 'right' => 'Справа', 'bottom' => 'Снизу', 'left' => 'Слева',
        'grid' => 'Сетка', 'size' => 'Шаг, мм', 'snap' => 'Привязка', 'visible' => 'Показывать', 'header' => 'Верхний колонтитул', 'footer' => 'Нижний колонтитул', 'text' => 'Текст',
        'pageNumbers' => 'Номера страниц', 'enabled' => 'Печатать', 'position' => 'Место', 'startAt' => 'Первый номер', 'hideFirst' => 'Скрыть на первом листе',
        'bold' => 'Полужирный', 'italic' => 'Курсив', 'underline' => 'Подчёркнутый', 'strike' => 'Зачёркнутый', 'superscript' => 'Надстрочный', 'subscript' => 'Подстрочный',
        'link' => 'Ссылка', 'href' => 'Адрес', 'textStyle' => 'Шрифт и цвет', 'type' => 'Начертание', 'attrs' => 'Параметры'];

    public function compare(array $before, array $after): array
    {
        $left = $this->collect($before); $right = $this->collect($after); $result = [];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $previous = $left[$key]['value'] ?? null; $next = $right[$key]['value'] ?? null;
            if ($previous === $next) continue;
            $entry = $right[$key] ?? $left[$key];
            unset($entry['value']);
            $result[] = [...$entry, 'before' => $previous, 'after' => $next, 'before_display' => $this->describe($previous), 'after_display' => $this->describe($next)];
        }
        return $result;
    }

    private function collect(array $document): array
    {
        $result = [];
        $visit = function (array $node, string $path, string $identity, ?string $clause = null) use (&$visit, &$result): void {
            $attrs = $node['attrs'] ?? [];
            if ($node['type'] === 'clause') { $clause = $attrs['id']; $identity = 'clause:'.$clause; }
            elseif (isset($attrs['layout']['id'])) $identity = 'block:'.$attrs['layout']['id'];
            $properties = array_intersect_key($attrs, array_flip($node['type'] === 'doc' ? ['layout', 'pageDecorations', 'locale'] : ['layout', 'format']));
            if ($node['type'] === 'doc') $properties['locale'] = $attrs['locale'] ?? 'ru-RU';
            if (isset($node['marks'])) {
                $marks = $node['marks']; usort($marks, static fn (array $a, array $b): int => strcmp($a['type'], $b['type'])); $properties['marks'] = $marks;
            }
            foreach ($properties as $property => $value) {
                if ($property === 'layout') unset($value['id'], $value['version']);
                if ($value === [] || $value === null) continue;
                $result[$identity.'.'.$property] = ['path' => $path, 'clause_id' => $clause, 'node_type' => $node['type'],
                    'label' => ($node['type'] === 'doc' ? 'Документ' : 'Фрагмент '.$path).' — '.self::LABELS[$property], 'value' => $this->canonical($value)];
            }
            foreach ($node['content'] ?? [] as $index => $child) $visit($child, $path === '' ? (string) ($index + 1) : $path.'.'.($index + 1), $identity.'.'.($index + 1), $clause);
        };
        $visit($document, '', 'document');
        return $result;
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }

    private function describe(mixed $value): string
    {
        if ($value === null || $value === []) return 'Не задано';
        if (is_bool($value)) return $value ? 'Да' : 'Нет';
        if (!is_array($value)) return self::LABELS[(string) $value] ?? match ((string) $value) {
            'center' => 'По центру', 'portrait' => 'Книжная', 'landscape' => 'Альбомная', 'current_total' => 'Номер и всего страниц', 'current' => 'Номер страницы', default => (string) $value,
        };
        $items = [];
        foreach ($value as $key => $item) $items[] = (is_string($key) ? (self::LABELS[$key] ?? $key).': ' : '').$this->describe($item);
        return implode('; ', $items);
    }
}
