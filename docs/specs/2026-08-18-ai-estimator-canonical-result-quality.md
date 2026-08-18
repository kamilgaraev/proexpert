# Качество канонических результатов AI-сметчика

## Цель

Результат разбора листа должен состоять из уникальных канонических фактов, сохранять происхождение каждого подтверждения и показываться в admin МОСТ понятными русскими формулировками. Изменение не затрагивает цены, нормативы, арифметику сметы, tenant/source/version fences, правила публикации или платные AI-вызовы.

## Production evidence

Диагностика выполнена read-only для обезличиваемой fixture реального прогона: документ 22 страницы, показательный план этажа — страница 5. На момент фиксации страница имела статус `ready`, 19 визуальных элементов, 3 изолированных элемента и завершённые роли `observer_literal`, `observer_construction`, `observer_risk`, `arbiter`.

Три observer payload содержали 17, 22 и 21 claim. Сохранённый arbitration payload содержал 60 решений — по одному решению на каждый claim, хотя часть решений ссылалась на те же сущность, тип, значение и единицу. Persistence создал 30 document facts. В `AtomicDocumentUnitPublicationWriter` identity строилась от `claim_id`, `level` безусловно проецировался в `floor_count`, а confidence accepted fact записывался как `1.0`. `ObservationClaim` не переносил исходный confidence. HTTP resource отдавал плоские технические `entityKey`/`factType`, а admin добавлял единицу к уже форматированному тексту и показывал технический тип как label.

## Поток данных

```text
observer_literal ─┐
observer_construction ─┼─> allowlisted claims + evidence ─> arbiter intents
observer_risk ────┘                                      │
                                                         v
                         canonical reducer: semantic identity, lineage,
                         conservative confidence, item quarantine
                                                         │
                           ┌─────────────────────────────┴──────────────────┐
                           v                                                v
                  project/document facts                         AdminResponse resource
                  authoritative persistence                     presentation DTO
                                                                            │
                                                                            v
                                                        typed admin normalizer + UI
```

## Backend canonical contract

### Observation claim

Каждый claim сохраняет server-owned scope и source locator, а также исходный `confidence` в диапазоне `0..1`. Для исторического payload без confidence используется честное значение `0.0`; явно переданный недопустимый confidence изолирует только claim. Evidence и claim по-прежнему обязаны совпадать по organization, project, session и source version.

### Семантическая identity

Canonical key включает нормализованные:

- entity identity;
- fact type;
- typed value;
- unit.

Разделители `.`, `_`, `-`, `:` в одной entity identity эквивалентны. Число без entity identity не является достаточным ключом: одинаковые `11100 мм` для разных осей или сущностей остаются разными фактами. Решения, связанные общим allowlisted supporting claim и описывающие одну semantic identity, схлопываются в одно решение.

### Lineage

У канонического факта сохраняются все уникальные supporting claim IDs, observer roles, исходные confidence, evidence refs и source locators. Порядок детерминирован. Нельзя добавлять lineage из другого tenant, project, session или source version.

### Confidence

Для accepted canonical fact confidence вычисляется только по уникальным независимым observer roles, чьи claims поддерживают semantic identity:

1. для каждой роли берётся максимальный исходный confidence среди её эквивалентных claims;
2. вычисляется среднее этих значений;
3. за каждую дополнительную независимую роль после первой добавляется `0.02`;
4. результат ограничивается диапазоном `0..0.99` и округляется до четырёх знаков.

Arbiter status сам по себе не увеличивает confidence до `1.00`. Candidate агрегирует независимые исходные confidence без бонуса за число ролей.

### Семантика уровней и этажности

- `level` и `elevation` проецируются как высотная отметка, включая `±0,000` и числовое `0` с единицей длины;
- `floor_count` принимается только как положительное целое число без единицы и при явном accepted claim типа `floor_count`;
- `level` никогда не проецируется в `floor_count`;
- невалидный отдельный факт помещается в карантин или не публикуется, не отменяя остальные факты страницы.

### Admin presentation DTO

`semantic_analysis.facts` содержит строго ограниченный список:

```json
{
  "canonical_id": "sha256:…",
  "entity_key": "building.overall_width",
  "fact_type": "dimension_chain",
  "label": "Габарит здания по оси X — 11 100 мм",
  "value": { "type": "number", "data": "11100" },
  "unit": "мм",
  "confidence": 0.99,
  "lineage": [
    {
      "claim_id": "literal:2",
      "role": "observer_literal",
      "confidence": 0.97,
      "evidence_ref": "literal:evidence:dimension-x"
    }
  ],
  "source": { "page_number": 5, "evidence_refs": ["literal:evidence:dimension-x"] }
}
```

Правила представления:

- `building.overall_width` → «Габарит здания по оси X»;
- `building.overall_height` → «Габарит здания по оси Y»;
- доказанный пролёт/высота/размер помещения или стены получает соответствующую подпись;
- размер без доказанного назначения → «Размер на чертеже»;
- room name и area одной entity объединяются: «Кухня-гостиная — 22,10 м²»;
- единица выводится ровно один раз;
- `level`, `dimension_chain`, `room`, `wall` и другие machine keys не используются как пользовательский label;
- evidence показывается отдельным secondary control «Открыть источник», а не заменяет название факта.

## Historical read boundary

Backend resource повторно редуцирует исторические arbitration decisions по semantic identity и общей подтверждающей lineage, затем формирует тот же DTO. Admin normalizer выполняет защитную детерминированную дедупликацию canonical DTO по entity/type/value/unit с объединением lineage/evidence. Разные entity/оси не объединяются. Исторические данные не переписываются.

## Fixture страницы 5

Fixture сохраняет production-shaped envelope observers → arbitration → semantic resource и подтверждённые данные: габариты 11 100 × 7 300 мм, общую площадь 72,19 м², помещения с площадями, отметку ±0,000/0 м, корректную этажность, каркасно-панельную технологию и состав наружной стены 229 мм. Tenant IDs, hashes, evidence IDs и служебные timestamps заменяются синтетическими значениями.

## Проверки

Backend PHPUnit/PostgreSQL:

- три observer claims одного факта дают один persisted canonical fact и объединённую lineage;
- одинаковое число разных entity/осей остаётся разными фактами;
- `±0,000` и `0 м` не становятся этажностью;
- положительный целый `floor_count` без единицы сохраняется;
- итоговый confidence не равен `1.00` без математического основания;
- один плохой claim не уничтожает страницу;
- room и area связываются в AdminResponse DTO;
- dimension получает доказанный label либо «Размер на чертеже»;
- production-shaped fixture проходит persistence replay и resource projection.

Admin Vitest/MSW:

- typed normalizer принимает production-shaped AdminResponse;
- русские labels отображаются без machine keys;
- unit не дублируется;
- room-area отображается одной строкой;
- historical duplicates отображаются один раз, разные entity сохраняются;
- evidence остаётся отдельной ссылкой на страницу источника.

## Release и canary

После merge штатно выполняются deploy workflows backend и admin. Canary не запускает обработку документов или AI: проверяются `/ready`, exact release SHA, защищённый endpoint с `401`, отсутствие новых ошибок в логах/GlitchTip и неизменность состояния production session/document/page, количества фактов и суммы учтённых AI-расходов.
