# Plan 1a Task 18 order amendment

Статус: accepted, forward-only.

Этот документ уточняет только порядок двух существующих Plan 1a DTO без изменения их публичных сигнатур.

## ReportCandidateValidationResult

`ReportCandidateValidationResult::__construct(array $items)` сохраняет исходный порядок уникальных элементов. Для результата Task 18 это порядок `CandidateReportDefinitionRegistry::candidateCodes()`.

Lookup по code и проверка duplicate code остаются без изменений. Лексическая сортировка элементов больше не является частью контракта.

## ReportDefinitionBindingMap

`ReportDefinitionBindingMap::__construct(array $bindings)` сохраняет исходный порядок associative map. Для опубликованного runtime map это порядок `ReportDefinitionRegistry::publishedCodes()`, то есть manifest order.

Проверка matching key/code и missing lookup остаются без изменений. Лексическая сортировка bindings больше не является частью контракта.

## Совместимость

- Публичные constructor и method signatures обоих DTO не изменены.
- Nominal candidate/published wrappers не изменены.
- `ReportDefinitionBindingAssembler::assemble(ReportDefinitionRegistry): ReportDefinitionBindingMap` не изменён.
- Plan 1a evidence artifacts и hashes не переопределяются: amendment является новым forward-only контрактом Task 18.
- Runtime consumers используют один container-owned singleton `ReportDefinitionBindingMap`; повторная runtime assembly запрещена.
