# Инцидент PDF-обработки AI-сметчика

## Цель

Сделать обработку многостраничного PDF устойчивой к усечённым ответам Vision, сохранить полезный первичный результат при сбое дополнительного анализа, восстановить полный идемпотентный журнал фактических AI-вызовов и выдать единый серверный контракт готовности документа.

## Контракт

- Обычный Vision-анализ и targeted reanalysis имеют независимые бюджеты вывода: безопасные значения по умолчанию 8192 и 6144, каждый ограничен hard cap 16384.
- Основная модель — `openai/gpt-5.6-luna`, `reasoning_effort=medium`. Запрос Luna содержит `max_tokens`, image input и strict JSON schema, но не содержит `temperature`.
- При первом pin новой операции приоритет модели: непустой `ESTIMATE_GENERATION_VISION_MODEL` → `models.vision` effective settings → versioned config default. Результат сохраняется в immutable operation snapshot; replay/recovery и targeted наследуют pinned model.
- Versioned migration добавляет Luna как новый active settings snapshot без изменения старых snapshots и уже pinned операций. Allowlist отклоняет неизвестную модель до HTTP.
- Тариф Luna: 135 RUB за миллион input tokens и 810 RUB за миллион completion tokens; reasoning уже включён в completion и не тарифицируется повторно.
- `finish_reason=length` является типизированным усечением. Один физический запрос учитывается один раз и не повторяется как идентичный terminal-запрос.
- Успешный primary сохраняется при усечении targeted enrichment. Страница получает ограничение и действие проверки вместо системного падения, если primary безопасно применим.
- Targeted-ответ содержит только дополнение к primary и затем безопасно объединяется с ним.
- External provider schema и сохранённый canonical `VisionAnalysisData` восстанавливаются разными методами.
- Каждый физический AI-запрос сохраняется ровно один раз. `request_context` всегда является JSON-объектом, включая пустой контекст.
- Сервер отдельно сообщает прогресс выполнения и готовность результата. Системный сбой запрещает переход к «Итогу», зелёную готовность и retry, если `retry_allowed=false`.
- Успешные страницы не теряются и входят в отдельный счётчик; queued/processing, review и terminal system failure не смешиваются.
- Ручной retry сохраняет готовые units/pages и повторяет только failed/breaker units; API предлагает его только при серверной eligibility.

## Production-shaped регрессии

- Primary `length` при 4096 и targeted `length` при 2048 воспроизводят документ 168 без содержимого исходного PDF.
- Targeted truncation после успешного primary сохраняет canonical primary и создаёт bounded review limitation.
- Canonical payload с provider/model/usage/project sheet metadata восстанавливается без повторной external-schema валидации.
- PostgreSQL fixture из семи физических вызовов сохраняет 7 строк, 53 546 input, 15 227 output и 29.343870 RUB; повторная запись тех же attempt ID не меняет итог.
- Outcome fixture: 2 успешные страницы, 3 terminal failures и 17 breaker failures; execution завершён, readiness заблокирована, retry запрещён.
- PostgreSQL pin fixture доказывает env override, fallback к effective profile, неизменность уже pinned operation и смену модели только для новой операции.

## Выпуск

Backend разворачивается раньше admin. Workflow, secrets, environments, infrastructure и production `.env` не меняются. После deploy документ 168 автоматически не запускается; разрешён только один последующий ручной контролируемый retry.
