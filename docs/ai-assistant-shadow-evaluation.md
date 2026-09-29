# Теневая проверка платного помощника МОСТ

Платное списание остаётся выключенным до фактической проверки и отдельного выпуска. Этот модуль читает локальный JSON и не вызывает модель, API или БД. Отчёт не подтверждает качество Luna без выполненных сценариев и сохранённых свидетельств.

## Локальная проверка

Полностью автономная команда, без загрузки Laravel и его service providers:

```powershell
php tests/Fixtures/AIAssistant/shadow-report.php --fixture > synthetic-traces.json
php tests/Fixtures/AIAssistant/shadow-report.php synthetic-traces.json
php tests/Fixtures/AIAssistant/shadow-report.php actual-traces.json
```

Коды выхода: 0 — фактический набор допускает рассмотрение; 1 — готовность заблокирована; 2 — некорректный файл или схема. Набор со `stage:synthetic` всегда возвращает 1. Приватные синтетические бизнес-данные можно использовать как вход реального вызова Luna, но тогда traces должны содержать фактический usage, evidence и `stage:actual`; fabricated usage/traces не засчитываются. JSON ограничен 32 MiB. Отчёты/трассы с чувствительными данными не добавлять в Git.

Laravel предоставляет `ai-credits:shadow-report {traces}`. Команда также не обращается к БД или провайдеру; для полностью автономного запуска используйте скрипт выше, поскольку стандартный artisan загружает приложение.

`tests/Fixtures/AIAssistant/shadow-scenarios.php` содержит 240 входов: 20 категорий × 3 разных задачи × 4 контекста прав, состояния объекта, организации, типа документа и денежных данных. Категории включают права, изоляцию, injection, денежные уточнения, токены, стоимость, файлы, OCR, снимки экрана, действия, гонки, память, индекс и ошибки. Четыре PNG в `screenshots/` — учебные снимки с явно синтетическими суммами. Спецификации вложений требуют реального захвата/файла в фактическом прогоне. Ни один сценарий набора не заявляет выполненный вызов или качество модели: `observed=not_run`, вызовы пустые. Настоящие PDF/XLSX/DOCX, обрезанные снимки и многопоточную нагрузку готовит тестовый оператор по спецификации, затем сохраняет фактические результаты.

Это набор заданий, а не выполненный acceptance test. Unit tests имитируют трассы исключительно для проверки арифметики, блокировок и подписи; их провайдер `unit_test_provider` не подтверждает качество Luna.

## Контракт трассы v1 и v2

v1 остаётся совместимым для честных оплаченных трасс. Для теневого прогона используйте `schema_version:2`, `charging_mode:shadow`. `charged_minor` содержит фактическое списание: при выключенной оплате это ноль. `projected_charge_minor` — отдельный прогноз округления успешной себестоимости по неизменному `pricing_snapshot` quote. Он не обрезается подтверждённым пределом: превышение `approved_minor` явно блокирует качество и approval. Фактическое платное списание по-прежнему ограничено подтверждением. Snapshot содержит `unit_minor`, `unit_cost_micro_rub`, `minimum_minor`, `charge_step_minor`. Завершённый серверный ответ без модели также учитывает минимальную цену действующей политики в прогнозе; фактическое теневое списание остаётся нулём.

Без `scenario_contract` действует `workflow_v1`: все 20 workflow-категорий обязательны минимум дважды. `scenario_contract:domain_v1` для schema v2 требует все 21 фиксированную категорию минимум дважды. Оба контракта требуют минимум 200 разных scenario ID, 200 различных содержательных входов и 200 успешных проверенных model-quality Luna-вызовов, все три профиля и все assertions. Приватные синтетические бизнес-данные допустимы только как input actual-сценария; synthetic/fabricated usage, traces или mock-результат не подтверждают модельный успех. Request ID и смена профиля не увеличивают различимость. `partial_run:true` блокирует качество и подпись. Контракт и частичность входят в подписанный отчёт; неизвестный контракт отклоняется. Evaluator v5 требует переоценки прежних подписанных отчётов.

Для v2 каждый внешний вызов дополнительно содержит `usage_source:provider_response|provider_invoice` и `provider_evidence_sha256`. Сохраняйте исходный ответ/счёт отдельно от агрегата. Токены из fallback токенизатора не проходят как usage. Модель `openai/gpt-6-luna` нормализуется в `gpt-6-luna`; исходное имя сохраняется в `provider_models`, `raw_model` можно передать отдельно. `provider_call_count` показывает фактически выполненные вызовы, а не число заданий.

Детерминированный completed без вызова assistant допускается только с `server_read_evidence:{verified:true,verifier,evidence_sha256,source_receipts:[...]}`. Проверяющий сверяет записи сервера и результат. Фиктивный вызов модели для покрытия не нужен. Для памяти без внешних вызовов разрешено `cost_coverage.memory:{mode:no_external_calls,verified:true,verifier,evidence_sha256,observed_external_call_count:0,source_code_sha256,record_snapshot_sha256}`: свидетельство объединяет измеренный журнал вызовов, снимок записей и используемый код памяти. Одновременно заявить это покрытие и внешний memory-вызов нельзя.

`quality_ready` оценивает фактические сценарии, права, свидетельства и полноту расходов отдельно от экономики. Для `economics_basis:prelaunch_projection` readiness требует schema v2, `assistant_revenue_minor:0`, подтверждённый `price_policy_evidence`, фактические измеренные внешние расходы shadow-прогона и расход не выше 30% согласованной консервативной проекции. Проекция основана на 3 990 ₽ / 5 000 включённых единиц; это не cash revenue. Фактическая оплаченная выручка отслеживается отдельно. Для `actual_revenue` нужны подтверждённые записи `source:settled_assistant_billing_ledger`, `verified:true`, сумма за тот же период, непустые `settled_payment_ids`, `verifier` и `evidence_sha256`. Тестовые зачисления не являются платежами.

Информационный вывод `pricing_projection` содержит `projection_only:true` и `eligible_for_approval:false`; он не является readiness report. Источник проекции — canonical 3 990 RUB / 5 000 единиц из `config/ModuleList/addons/ai-assistant.json` и credit policy. `nominal_full_consumption_cost_micro_rub` не доказывает фактическую себестоимость периода. Для launch readiness используется отдельное проверяемое `prelaunch_projection` evidence и реальные внешние расходы.

Supplier pricing версии 2 использует точные тарифы [Timeweb AI Gateway](https://timeweb.cloud/services/ai-gateway): Luna 13,5 RUB/M input и 67,5 RUB/M output; text-embedding-3-large 45 RUB/M input и 0 output. Снимок от 2026-09-29 хранится отдельно в приватном `assistant-shadow-bridge/pricing-evidence.json`; HTML SHA-256 `865885b52436150331e3bfe5a746faea94bccf3f82a324de91bc4e84741b6e9d`. Отображаемые округлённые 14/68 не используются для расчёта новых quote. Уже созданные quote сохраняют свою цену. Полный short-вызов 8192/1024 стоит 179712 микрорублей, поэтому соответствует одной единице при факторе 0,18; округлённый тариф ошибочно давал 184320 и полторы единицы.

Embedding без provider usage сохраняет вектор для поиска. В журнале `usage_source:unavailable`, `provider_usage_available:false`, `cost_available:false`, а `estimated_input_tokens` хранится отдельно. Нулевые числовые поля такой записи означают отсутствие измерения, а не подтверждённый бесплатный вызов. Прогноз по длине текста не переносится в итоговый расход и не подтверждает полноту для paid approval.

`php artisan ai-credits:shadow-report actual-traces.json --quality-only` возвращает код по `quality_ready` и не подписывает отчёт. Совместное использование с `--approve-ready` отклоняется. Обычная команда сохраняет строгий статус платной готовности.

Корневые поля:

- `schema_version:2`, `stage:actual|synthetic`, `policy_hash`, `scenario_contract` и `economics_basis` (`actual_revenue` или `prelaunch_projection`).
- `period:{started_at,ended_at}`: разные даты ISO-8601, начало раньше конца. Период должен быть завершён до approval; для launch approval он должен укладываться в допустимый период измерения до даты подписи.
- `assistant_revenue_minor`: при `actual_revenue` — подтверждённая фактическая сумма в копейках. При `prelaunch_projection` должно быть ровно `0`; проекция хранится в отдельных полях и не выдаётся за выручку.
- `cost_coverage:{assistant,memory,index,errors,ocr}`: все пять значений `true` подтверждают сверку полноты внешних расходов за период.
- `scenarios`: массив сценариев; `background_calls`: все внешние расходы периода, не включённые в вызовы сценариев, без двойного учёта.

Сценарий:

- Уникальные `id`, `category` из `AICreditReadinessService::CATEGORIES`, `requested_profile:short|normal|detailed`.
- `input:{message,context?,followup?,attachment_spec?,attachments?}`. Различимость проверяется по этим содержательным полям; request_id и произвольные верхнеуровневые метки её не увеличивают.
- `outcome:completed|blocked|error|cancelled`, `provider_calls:[]`.
- `successful_cost_micro_rub`, `estimated_minor`, `approved_minor`, `charged_minor`: неотрицательные целые числа, первые — микрорубли, остальные — сотые единицы. Неуспешные и фоновые расходы не прибавляются к успешной себестоимости задачи.
- Для actual: `execution_evidence_sha256` — SHA-256 обезличенного протокола выполнения.
- `assertions` обязательно содержит `rights`, `leak`, `unconfirmed_actions`, `factual_amounts`, `business_quality`. Каждая запись: `{expected:<scalar>,observed:<scalar>,verifier:<nonempty>,evidence_sha256:<64 hex>}`. Сравнение строгое. Проверяющий должен доказать текущие права, отсутствие утечки/неподтверждённых действий, серверное происхождение сумм и предметный результат задачи. Простое утверждение модели о собственной правильности не является свидетельством.

Вызов:

```json
{"kind":"assistant","generative":true,"provider":"actual-provider","model":"gpt-6-luna","success":true,"cost_micro_rub":10000,"input_tokens":100,"output_tokens":20,"evidence":"provider_usage"}
```

`kind`: assistant, memory, index, ocr, estimate_generation. Фактические генеративные вызовы обязаны использовать `gpt-6-luna`. Embedding имеет `generative:false` и допускается только в index. `evidence=provider_usage` означает, что токены и стоимость сверены с фактическим usage/счётом, включая расход неудачных попыток. Факт провайдера и суммы нельзя заменять оценкой токенизатора. Сервис проверяет структуру/метаданные, но не обращается к внешнему счёту: полноту и достоверность подтверждает оператор до утверждения. Actual требует наблюдавшиеся расходы assistant, memory, index, ocr и минимум одну фактическую ошибку провайдера; один флаг coverage без вызовов не достаточен.

## Условия допуска и расчёт

Не менее 200 разных содержательных входов, не менее двух случаев каждой из 20 категорий, все три профиля. У завершённого сценария обязателен успешный вызов assistant либо проверенное серверное чтение v2. Все бизнес-проверки совпадают с ожидаемыми результатами и содержат свидетельства. Остальные ошибки схемы/стоимости/прав блокируют допуск.

Себестоимость суммируется в микрорублях без промежуточного округления. Успешные assistant/memory/ocr внутри задачи формируют её стоимость. Списание завершённой задачи:

`max(minimum_units_minor, ceil(successful_cost_micro_rub * unit_minor / (round(rub_per_unit * 1e6) * charge_step_minor)) * charge_step_minor)`.

Starter policy v1: 0,18 рубля на единицу, шаг 0,5 единицы, минимум 0,5. Предел подтверждения не превышается; approved не превышает estimated. blocked/error/cancelled списывают ноль. Конфигурация версии/цен/шага читается с сервера, а не из UI.

`normal_spend_minor` показывает число успешных normal, min/max/mean и сколько попало в 0,5–1,5 единицы. `normal_spend_basis` явно указывает actual paid для v1 или projected immutable quote для v2; `actual_normal_spend_minor` отдельно сохраняет реальные списания. Диапазон сам по себе не является обещанием для каждого сложного normal запроса и не блокирует допускающий отчёт. Пустой набор успешных normal блокирует допуск.

Общая себестоимость assistant+memory+index+ocr, включая ошибки, должна быть ≤30% фактической выручки помощника за тот же период. estimate_generation выводится отдельно и не смешивается с этой выручкой. `errors_cost_micro_rub` — подмножество общих расходов, его нельзя прибавлять повторно.

## Защищённое утверждение

`AICreditReadinessService(?array $policy=null)`:

- `evaluate(array $traces):array`, `reportFromFile(string $path):array`.
- `policyFingerprint():string`: SHA-256 канонической конфигурации price_version, unit_minor, rub_per_unit, minimum_units_minor, charge_step_minor, profiles, pricing, packs. Перестановка JSON-ключей не меняет hash; смена политики делает прежнее утверждение недействительным.
- `approveReport(array $report,string $key,?int $now=null):array`.
- `verifyApproval(array $approval,string $key,?int $now=null):bool`: проверяет подпись, тип approval, период, версию оценщика, policy hash, implementation fingerprint и price evidence. `stage:synthetic`, подмена или недостаточный набор закрывают допуск. Launch approval допускает приватные synthetic business inputs, только если модельные usage/traces реальны.

Отчёт содержит ready_for_approval, reasons, traces_hash, policy_hash, report_hash и evaluator_version. Hash не является подписью; HMAC создаётся только следующим явным действием оператора:

```powershell
php artisan ai-credits:shadow-report actual-traces.json --approve-ready
```

Approval двух видов. `approveReport` создаёт time-bound envelope с `expires_at`; для него `readiness_ttl_seconds` по умолчанию 7 суток. Launch `approveLaunchReport` использует `approval_type:version_bound_launch` и `expires_at:null`: envelope не истекает по TTL, но подписывает report с implementation fingerprint и price policy. Конфигурированный `MOST_RELEASE_SHA` проверяется и записывается в подписанный envelope при создании; verifier не сравнивает approval с произвольным текущим Git HEAD. Для launch-периода действует отдельное ограничение свежести относительно времени подписи. По умолчанию файл сохраняется атомарно в `storage/app/private/assistant-credit-readiness.json` с правами 0600, каталог 0700. На Windows нужна приватная ACL. `--output` предназначен доверенному оператору, путь не принимается из HTTP. Ключ — server app.key; при его смене нужна новая подпись. Сервис не включает оплату.

Подписанный отчёт доверяет оператору, который сверяет первичные свидетельства и полный период. Самодекларируемый JSON не становится независимым доказательством качества. Manifest содержит 389 сценариев; реальные прогоны и порог ≥200 успешных проверенных входов ещё не выполнены. План: первые 8 сценариев, затем один полный прогон 389; `smoke1` дублирует первые 8 и transport probes. PostgreSQL inventory имеет прерванные/частичные результаты и отдельные узкие targets; они не подтверждают полный PASS. Bridge recovery, transport probes, inventory markers и unit tests не являются сценариями Luna. Production readiness и включение оплаты не заявляются; выпуск остаётся full-only после всех gates. Текущие root-run проверки, DB backup и release status находятся в [чеклисте выпуска](ai-assistant-release-checklist.md).
