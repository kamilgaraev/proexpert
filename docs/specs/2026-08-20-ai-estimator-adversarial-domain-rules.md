# AI-сметчик: доменные правила адверсариальных границ

## Ordinal и identity

- Instance распознаётся только отдельной bounded-грамматикой ASCII decimal после токена объекта.
- Отсутствие instance отлично от `0`; ведущие нули не меняют ordinal.
- Signed, Unicode, пробельные, смешанные и oversized формы не интерпретируются как ordinal и получают bounded unsupported identity.
- Tenant, project, session, document, page, source version, evidence, room и object boundaries остаются частью внешнего canonical scope.

## Group arbitration

- Решение редуцируется по всей canonical physical group независимо от `claim_id`.
- Закрытый порядок консервативности: `accepted < candidate < conditional < unresolved < ambiguous < rejected`.
- Более консервативное minority evidence нельзя скрыть accepted-решением; конфликт получает typed limitation.
- Unknown status нормализуется в `unresolved`, unknown reason не показывается и не повышает доверие.
- Primary decision, reason и lineage зависят только от multiset и не зависят от порядка входа.

## Quantity grammar

- Parser возвращает `count`, `not_count` или `ambiguous` и provenance.
- Count допустим только для одного целого ASCII-числа `1..9999` с согласованным count marker или RU/EN исчисляемым существительным объекта.
- Размеры, площади, объёмы, диаметры, высоты, отметки, уклоны, оси, этажи, годы, марки, модели и артикулы не являются count.
- Несколько чисел, Unicode digits, decimal и смешанные object families дают `ambiguous`, а наружу — `quantity=null` и uncertainty.

## Context classification

- Room, category, entity key и label оцениваются как независимые сигналы.
- Согласованный kitchen context даёт `kitchen_sink`, bathroom/sanitary — `washbasin`.
- Конфликт сильных сигналов даёт `unknown_fixture`, русскую нейтральную подпись и `requires_confirmation`.
- Unknown identity сохраняет bounded fingerprint исходного object key и не объединяется с уверенным типом.
