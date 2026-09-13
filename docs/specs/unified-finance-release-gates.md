# Приёмка единой привязки смет

Состояние на 13 сентября 2026 года. Это перечень доказательств и оставшихся условий выпуска, а не объявление о готовности production. Согласованный объём сохраняется в `unified-estimate-contract-finance.md`, история проверок — в `unified-finance-progress.md`.

## Проверено локально

Полный `tests/Feature/ContractManagement/EstimateFinanceTest.php`: **101 тест, 1035 проверок**, PostgreSQL 16, `finance-final-integration-tests.log`, 12:38.758, завершение 0. Ниже указаны конкретные группы этого успешно завершённого набора.

| Требование | Доказательство и граница |
| --- | --- |
| Доходная и расходная стороны, частичные объёмы, несколько исполнителей | `customer_and_split_cost_volumes_are_independent_and_replay_is_idempotent`, `overallocation_is_rejected_without_partial_writes` |
| Атомарное сохранение обеих сторон | `batch_save_rolls_back_both_sides_when_history_write_fails` |
| Работа и включённые ресурсы без повторного суммирования | `materials_included_and_mixed_procurement_roll_up_once`, `native_resource_volume_is_separate_from_work_and_cannot_be_removed_after_acceptance` |
| Исключённые позиции не раздувают покрытие | `client_coverage_regression_keeps_sixteen_positions_without_excluded_amount`: 16 позиций, 26 533 906,30 ₽, исключено 154 253 129,99 ₽. Это воспроизведённые данные, не production 275/436 |
| Цена за единицу, общая сумма и точное округление | `exact_distribution_has_deterministic_rounding_and_rejects_zero_base`, `preview_changes_only_selected_contract_lines_and_checks_manual_total` |
| Независимый НДС, отсутствие повторного начисления, отличие 0% от отсутствия НДС | `explicit_vat_modes_are_independent_of_estimate_and_repeated_save`, `without_vat_is_distinct_from_zero_rate_and_has_known_margin`, `explicit_tax_mode_rejects_missing_or_contradictory_rate` |
| Изменения исходной сметы требуют явного обновления условий | `repricing_requires_explicit_preview_and_does_not_modify_estimate`, `attach_adapter_keeps_contract_price_when_estimate_changes` |
| Новая цена применяется только к остатку | `new_price_applies_only_to_remaining_volume_and_total_keeps_accepted_cost`, `manual_execution_keeps_historical_price_and_unknown_volume_blocks_repricing` |
| Версии, стабильные идентификаторы, история | `edit_preserves_allocation_and_projection_identifiers`, `condition_history_preserves_snapshots_after_edit_and_delete`, `stale_condition_version_is_rejected_even_with_current_estimate_revision` |
| Защита принятого факта и корректное явное отсоединение | `accepted_volume_cannot_be_removed_or_reduced_and_annulment_releases_it`, `empty_projection_cannot_be_unlinked_after_native_acceptance`, `linked_sources_cannot_be_deleted_and_explicit_release_preserves_link_id` |
| Утверждённые/подписанные акты учитываются один раз, черновики не становятся фактом | `execution_report_uses_approved_documents_and_keeps_unallocated_amount`, `signing_keeps_manual_execution_current_and_annulment_preserves_its_history`, `manual_quantity_counts_native_lines_once_when_act_also_has_legacy_works` |
| Ручное распределение факта и контроль общего остатка документа | `execution_distribution_shares_act_capacity_between_estimates_and_checks_access`, `execution_distribution_saves_exact_net_replays_and_limits_native_remainder` |
| Частичные оплаты, возвраты, авансы и отсутствие двойного учёта | `cash_sources_keep_partial_payments_refunds_and_currency_separate`, `cash_distribution_caps_shared_payment_across_estimates_and_rejects_atomic_invalid_line`, `project_cash_counts_shared_contract_payments_once`. Дополнительно 6/18 проверок банковской идемпотентности и возвратов: `finance-payment-release-tests.log` |
| Собственные затраты по документу или подтверждённой ручной записи | `own_cost_registration_previews_tax_and_replays_without_duplicate_expense`, `own_cost_document_registration_rejects_changed_pending_and_duplicate_source`, `own_cost_distribution_caps_all_estimates_and_keeps_exact_net_and_history` |
| Неизвестные условия не превращаются в нулевую цену/полную маржу; валюты разделены | `unknown_tax_and_missing_cost_never_produce_full_margin`, `mixed_currencies_keep_known_totals_separate`, `unknown_amount_remains_unknown_on_subsequent_saves` |
| Серверные права, изоляция организации, отсутствие запросов к запрещённому факту | `financial_access_is_separate_and_rejects_other_organizations`, `finance_permission_alone_cannot_create_contract_allocations`, `cash_report_does_not_query_payments_without_both_permissions`, `execution_report_does_not_query_acts_without_permission` |
| Конкурентное редактирование и повторные запросы | `parallel_saves_cannot_spend_the_same_volume_twice`, `manual_execution_waits_for_native_contract_volume_and_rechecks_after_commit`, `own_cost_edits_from_different_estimates_wait_and_reject_stale_source_version` |
| Согласованные итоги сметы, договора, проекта и Excel | `section_project_and_excel_use_identical_server_totals_and_safe_text`, числовая регрессия покрытия, `contract_reads_financial_conditions_instead_of_stale_projection_amounts` |
| Повторяемый перенос, исходные идентификаторы и утверждённый факт | `migration_plan_pages_preserved_links_without_inventing_tax_or_writing_data`, `migration_preserves_signed_act_lines_and_payment_history`, `migration_requires_contract_edit_and_does_not_resurrect_retired_conditions`, `migration_inventory_is_read_only_paged_and_scoped`. Только изолированные тестовые данные |
| Большая импортированная смета без запроса на каждую строку | Настоящий XLSX на 8001 позицию: `EstimateImportGrandSmetaGoldenTest`, 2/46, `finance-imported-large-tests.log`, 11:36.186, 964MB. Отчёт <=40 запросов; дополнительно полный финансовый набор проверяет 8001 работу + 8001 ресурс и массовое сохранение |

## Интерфейс и интеграция

- Админка: после включения актуального main прошли 20 файлов / 83 теста и TypeScript; отдельно 5 проверок массового выбора. Результаты и границы указаны в журнале. Локальная production-сборка запрещена правилами проекта; штатные сборки и деплои завершились успешно, ссылки ниже.
- Chromium: реальная форма `EstimateFinanceDrawer`, обе стороны с независимыми ценами/НДС, предпросмотр и один успешный PUT. Данные формы сохранились после сетевой ошибки. На ширине 390 px нет горизонтального переполнения. HTTP-ответы подменены; это не сквозная проверка с сервером.
- Chromium: предварительная сверка старых связей, чтение двух страниц, CSV с точными исходными суммами, режим без редактирования. HTTP-ответы подменены.
- Старые attach/detach/sync/VAT точки входа передают actor в общий сервис. Найден старый `JournalContractCoverageService.ensureCoverage` без actor, но прямых вызовов этого метода в `app` не обнаружено; активные потребители используют только `resolve`/`resolveContractLink`. Это ограниченный поиск прямых вызовов, не доказательство отсутствия динамического вызова.
- Финансовые права уже объявлены в модуле смет и переведены в `lang/ru/permissions.php`; новый мобильный финансовый экран не добавляется. Проверка назначения этих прав в реальном личном кабинете остаётся частью сквозной приёмки.

## Опубликованный выпуск и сверка production

Финансовые изменения опубликованы штатными процессами без изменения CI/CD:

- Backend: PR 711, 712, 713; завершающая версия `e025b03f5f1a9e0ff9eeaaa6101ddaa88a91346d`, [успешный запуск 34758897951](https://github.com/kamilgaraev/proexpert/actions/runs/34758897951).
- Админка: PR 401, 402, 403; завершающая версия `8a0c510c1ff25830177f16920c0068eec35a7834`, [успешный запуск 34758901146](https://github.com/kamilgaraev/prohelper_admin/actions/runs/34758901146).
- Эти идентификаторы относятся к данному финансовому выпуску; последующие релизы других задач ими не описываются.
- Исходная инвентаризация содержит 4685 связей в 8 организациях. После основного выпуска идентификаторы и хеши сохранённых значений совпали. Все 7 договоров организации клиента также сохранились без изменений.
- Реальный расчёт договора 275 / сметы 436: 16 учитываемых позиций, 26 533 906,30 ₽. Исключённые 143 позиции на 154 253 129,99 ₽ не прибавляются.
- До/после выпуска и после учебного переноса совпали контрольные хеши 119 актов, 31 строки актов, 31 старой связи выполнения, 67 транзакций и 255 платёжных документов.
- Частные инвентаризации и контрольные хеши сохранены локально в `output/finance-release`; в Git не публикуются.

## Сквозная проверка учебной организации

В организации 38, проекте 52 открыты представления «План», «Выполнение», «Деньги» и предварительная сверка сметы 423 на реальном API.

Через интерфейс перенесена связь 472: создано одно финансовое распределение; исходный ID, объём 1, сумма 152,40 ₽, даты и примечание сохранены. НДС и состав цены остались неподтверждёнными. Защита утверждённой сметы сохранена. Конфликт её защитного триггера с финансовой версией исправлен в PR 712: регрессия и сохранность подписанного факта — 2 теста / 22 проверки.

Карточка покрытия сметы показывает сохранённую сумму 152,40 ₽ отдельно от неизвестных налоговых условий. Неподтверждённая сумма не превращается в ноль и не включается в подтверждённую маржу. Дополнительная регрессия переноса — 1 тест / 13 проверок; проверки nullable-адаптера и компонентов, TypeScript, ESLint, PHPStan прошли.

Из договора 268 через «Сметы» → «Изменить привязку» открылась та же форма с объёмом 1 и суммой 152,40 ₽. Первая загрузка дала ошибку; штатное «Повторить» открыло форму. Причина первого сбоя не установлена. В этой проверке условия не сохранялись; это доказательство чтения и повторной загрузки, а не полного редактирования. В сводке договора неподтверждённые итоги пока обозначены «Требуется оценка», сохранённая сумма доступна внутри формы.

## Незавершённая приёмка и ограничение пользователя

Пользователь указал: «Не надо трогать аккаунт клиента». Вход под клиентом, выдача прав и изменение его данных не выполняются. Запрос другого сохранённого аккаунта снят; это ограничение не следует обходить.

Полный пользовательский маршрут с назначением прав в ЛК, сохранением и редактированием условий, переходами к актам/оплатам в организации клиента не подтверждён. Её связи не перенесены: отчёт содержит 159 связей с незаписанными НДС/составом цены. Условия не восстанавливались из разницы сумм. Учебная проверка и изолированные тесты не заменяют клиентскую приёмку.

Цель целиком не объявляется выполненной. [Статья бизнес-процесса Pro-A-74](https://prohelper.youtrack.cloud/articles/Pro-A-74) обновлена опубликованными версиями, фактическими результатами и этим ограничением.
