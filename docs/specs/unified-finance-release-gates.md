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

- Админка: после включения актуального main прошли 20 файлов / 83 теста и TypeScript; отдельно 5 проверок массового выбора. Результаты и границы указаны в журнале. Локальная production-сборка запрещена правилами проекта; штатная сборка ещё не запускалась.
- Chromium: реальная форма `EstimateFinanceDrawer`, обе стороны с независимыми ценами/НДС, предпросмотр и один успешный PUT. Данные формы сохранились после сетевой ошибки. На ширине 390 px нет горизонтального переполнения. HTTP-ответы подменены; это не сквозная проверка с сервером.
- Chromium: предварительная сверка старых связей, чтение двух страниц, CSV с точными исходными суммами, режим без редактирования. HTTP-ответы подменены.
- Старые attach/detach/sync/VAT точки входа передают actor в общий сервис. Найден старый `JournalContractCoverageService.ensureCoverage` без actor, но прямых вызовов этого метода в `app` не обнаружено; активные потребители используют только `resolve`/`resolveContractLink`. Это ограниченный поиск прямых вызовов, не доказательство отсутствия динамического вызова.
- Финансовые права уже объявлены в модуле смет и переведены в `lang/ru/permissions.php`; новый мобильный финансовый экран не добавляется. Проверка назначения этих прав в реальном личном кабинете остаётся частью сквозной приёмки.

## Ещё не выполнено

1. Получить исходную production-инвентаризацию всех организаций и реальные данные договора 275 / сметы 436. SSH `codex-ro@89.169.44.117` завершается banner timeout до авторизации; вопрос об актуальности доступа передан пользователю.
2. Сверить старые суммы/идентификаторы/факт, определить фактически однозначные связи, выполнить повторяемый перенос и сравнение до/после. Unknown НДС и состав цены не подтверждать предположением. Локальные тесты переноса не заменяют эту операцию.
3. Выполнить полный пользовательский маршрут с реальными правами: назначение роли в ЛК, выбор состава сметы, сохранение/редактирование условий из сметы и договора, переходы к акту/оплате и итогам. Изолированная браузерная форма не закрывает этот пункт.
4. Завершить ревью и штатный выпуск обеих частей, проверить результаты CI/CD и клиентскую организацию. Обновить статус статьи Pro-A-74 фактическими версиями выпуска. Текущая статья явно обозначает процесс как находящийся в разработке.

Черновики: backend [PR 711](https://github.com/kamilgaraev/proexpert/pull/711), админка [PR 401](https://github.com/kamilgaraev/prohelper_admin/pull/401). На момент создания оба mergeable, обязательные PR-проверки GitHub не отображаются. Слияние, production-миграции, перенос и деплой не выполнялись.
