# Покрытие RAG AI-ассистента

Дата актуализации: 2026-05-30.

Документ фиксирует фактический контракт RAG-индекса: какие источники регистрируются, какие `entity_type` попадают в индекс, как они отдаются в API и чем проверяются. Если добавляется новая бизнес-сущность для ассистента, она должна появиться здесь, в `RagSourceRegistry`, в навигации источников и в тестах.

## Контур индексации

- Источники регистрируются в `App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider` через `RagSourceRegistry`.
- API статуса возвращает `source_catalog`, сформированный из включенных источников реестра.
- Ручная и плановая переиндексация принимают только `source_type`, существующие в `RagSourceRegistry::enabledSourceTypes()`.
- `RagIndexer` режет длинный контент на чанки по `ai-assistant.rag.chunk_chars`, не индексирует пустой нормализованный текст и хранит хэш на уровне чанка.
- Для OpenAI embeddings параметр `dimensions` передается только для моделей `text-embedding-3*`, если размерность задана в конфиге.
- Плановый запуск использует `--stale`, чтобы не гонять полный reindex организаций с уже свежим успешным прогоном.

## Матрица источников

| `source_type` | `entity_type` в индексе | Основной смысл |
| --- | --- | --- |
| `project` | `project` | Паспорт проекта и ключевые атрибуты. |
| `schedule` | `schedule`, `schedule_task` | Графики и задачи графиков. |
| `contract` | `contract` | Договоры, контрагенты, суммы и статусы. |
| `estimate` | `estimate` | Сметы, разделы и ключевые позиции. |
| `estimate_reference` | `estimate_template`, `estimate_library_item`, `estimate_catalog_item`, `normative_rate` | Шаблоны, библиотека и нормативная база для смет. |
| `procurement` | `purchase_request`, `supplier_request`, `supplier_proposal`, `supplier_proposal_decision`, `purchase_order`, `purchase_receipt`, `procurement_approval`, `procurement_audit_event` | Заявки, поставщики, предложения, заказы, приемки, согласования и аудит закупок. |
| `warehouse` | `project_material_delivery`, `warehouse_balance`, `warehouse_movement`, `warehouse_project_allocation`, `asset_reservation`, `inventory_act`, `warehouse_storage_cell`, `warehouse_task`, `warehouse_asset` | Поставки, остатки, движения, распределения, резервы, инвентаризации, ячейки, задачи и активы склада. |
| `site_request` | `site_request` | Заявки участка. |
| `work_completion` | `completed_work` | Выполненные работы. |
| `construction_journal` | `construction_journal_entry` | Записи журналов работ с производственными фактами. |
| `performance_act` | `performance_act` | Акты выполненных работ. |
| `payment` | `payment_document` | Платежные документы и их связь с проектами/договорами. |
| `quality_executive_docs` | `quality_defect`, `executive_document_set`, `executive_document` | Дефекты качества и исполнительная документация. |
| `project_pulse` | `project_pulse_report` | Сводные отчеты Project Pulse. |
| `safety` | `safety_incident`, `safety_violation`, `safety_work_permit`, `safety_briefing`, `safety_corrective_action` | Охрана труда, нарушения, наряды, инструктажи и корректирующие действия. |
| `machinery` | `machinery_asset`, `machinery_assignment`, `machinery_shift_report`, `machinery_downtime`, `machinery_maintenance_order`, `machinery_fuel_issue`, `machinery_production_record` | Техника, назначения, сменные отчеты, простои, ТО, топливо и выработка. |
| `production_labor` | `production_labor_work_order`, `production_labor_work_order_line`, `production_labor_timesheet`, `production_labor_timesheet_entry`, `production_labor_output_entry`, `production_labor_payroll_accrual` | Наряды, строки работ, табели, выработка и начисления. |
| `change_management` | `change_management_rfi`, `change_request`, `change_claim`, `change_impact`, `change_approval`, `variation_order` | RFI, изменения, претензии, влияния, согласования и variation orders. |
| `handover_acceptance` | `project_location`, `acceptance_scope`, `acceptance_session`, `acceptance_checklist`, `acceptance_checklist_item`, `acceptance_finding`, `acceptance_signoff`, `handover_package`, `handover_package_document` | Локации, зоны приемки, сессии, чек-листы, замечания, подписания и пакеты передачи. |

## API и UI контракт

- `GET /api/v1/admin/ai-assistant/rag/status` возвращает `source_catalog: [{ type, enabled }]`.
- `POST /api/v1/admin/ai-assistant/rag/reindex` валидирует `source_type` по активному реестру источников. Неизвестные или отключенные типы не должны ставиться в очередь.
- `rag_context.sources[*].navigation_target.route` формируется в backend для всех поддержанных `entity_type`.
- Админка нормализует `source_catalog`, показывает человекочитаемые названия источников и строит переходы для всех новых групп: safety, machinery, production labor, change management, handover acceptance, procurement, warehouse, schedule и сметный блок.

## Эксплуатация

Точечный reindex организации:

```powershell
php artisan ai-assistant:rag-backfill <organization_id> --sync
```

Точечный reindex источника:

```powershell
php artisan ai-assistant:rag-backfill <organization_id> --source_type=safety --sync
```

Очередь по всем активным организациям:

```powershell
php artisan ai-assistant:rag-backfill --all --limit=50
```

Плановый stale-only запуск:

```powershell
php artisan ai-assistant:rag-backfill --all --stale --stale-after-hours=24 --limit=50
```

Синхронный `--all --sync --force` нужен только для контролируемой диагностики или операторского backfill после деплоя. Локально миграции не запускать.

## Проверки регрессии

Backend:

```powershell
php artisan test tests\Unit\AIAssistant\Rag --stop-on-failure
php artisan test tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php tests\Feature\Api\V1\Admin\AIAssistantRagOperationsTest.php --stop-on-failure
php artisan test tests\Feature\Console\AIAssistantRagBackfillCommandTest.php --stop-on-failure
php artisan test tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php --stop-on-failure
vendor\bin\phpstan.bat analyse app\BusinessModules\Features\AIAssistant --memory-limit=1G
```

Admin:

```powershell
npx tsc --noEmit
npx vitest run src\pages\AIAssistant\ragSources.test.ts src\services\aiAssistantService.test.ts
```

Browser smoke обязателен, если админка уже запущена и доступна локально или по внешнему URL. Dev-сервер только ради smoke-проверки не запускать.

## Правило добавления нового источника

Новый источник считается завершенным только если выполнены все пункты:

- есть полноценный `RagSourceCollectorInterface` без пустых заглушек и raw JSON dump в `content`;
- источник зарегистрирован в `AIAssistantServiceProvider`;
- `entity_type` имеет backend `navigation_target`, если для сущности есть ожидаемый экран;
- admin знает человекочитаемый label и fallback-навигацию;
- `RagSourceCollectorsTest`, `RagSourceRegistryTest`, `RagPromptContextBuilderTest`, admin unit tests и encoding-test покрывают изменение;
- документация в этом файле обновлена.
