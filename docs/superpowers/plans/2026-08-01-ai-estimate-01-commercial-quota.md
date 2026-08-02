# Коммерческий контур AI-смет: план реализации

> **Для агентных исполнителей:** придерживаться TDD: сначала написать падающий тест, затем минимальную реализацию, после этого выполнить рефакторинг и релевантные проверки. В плане используются флажки `- [ ]`.

**Цель:** установить цену 500 ₽ за дополнительную AI-смету и сделать квоту `ai_estimates_month` фактически исполняемой.

**Архитектура:** коммерческая квота является единственной пользовательской единицей потребления. Резервирование выполняется транзакционно в начале генерации, возврат — только при техническом завершении без результата.

**Технологии:** Laravel, PostgreSQL, существующие CommercialQuotaService и биллинговый React UI.

## Global Constraints

- Цена дополнительной единицы: 500 ₽.
- Не менять пользовательскую модель на оплату внутренних AI-вызовов или страниц.
- Операционный AI-бюджет не показывать в биллинге пользователя.

### Task 1: Исправить каталог цены и отображение квоты

**Files:**
- Modify: `config/commercial_limits.php`
- Modify: `lang/ru/billing.php`
- Modify: `prohelper_admin/src/pages/dashboard/BillingPage.tsx`
- Test: `tests/Unit/Services/Billing/CommercialQuotaServiceTest.php`

- [ ] Заменить `extra_ai_estimates.price_minor` с `5000` на `50000`, сохранив единицу `estimate` и шаг продажи.
- [ ] Обновить переводы, чтобы интерфейс называл ресурс «Дополнительная AI-смета» и показывал «500 ₽/месяц за единицу».
- [ ] Убрать из биллингового интерфейса любые формулировки о токенах, страницах или внутренней себестоимости.
- [ ] Добавить тест каталога: цена одной дополнительной AI-сметы равна 50000 копеек и доступна только с модулем `ai-estimates`.
- [ ] Выполнить целевой PHP-тест и `npx tsc --noEmit` в `prohelper_admin`.

### Task 2: Реализовать фактический учёт AI-смет

**Files:**
- Modify: `app/Services/Billing/CommercialQuotaService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Services/Billing/AiEstimateQuotaService.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Generation/StartEstimateGeneration.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Workflow/EstimateGenerationWorkflow.php`
- Test: `tests/Feature/EstimateGeneration/AiEstimateQuotaTest.php`

- [ ] Написать тесты: успешный старт резервирует одну квоту; повтор с тем же ключом не резервирует вторую; техническая ошибка до результата возвращает квоту; ручная правка и автоматический повтор не меняют использование.
- [ ] Заменить возвращаемое сейчас фиктивное значение `ai_estimates_month: 0` на подсчёт подтверждённых резервов текущего календарного месяца.
- [ ] Создать сервис, который проверяет доступный лимит, создаёт идемпотентный резерв сессии и освобождает его по точному набору терминальных технических статусов.
- [ ] Встроить вызовы сервиса в start/failure workflow без переноса бизнес-правил в контроллеры.
- [ ] Запустить целевой feature-тест и Larastan на новых/изменённых PHP-файлах.

### Task 3: Показать понятное состояние в рабочем сценарии

**Files:**
- Modify: `prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`
- Create: `prohelper_admin/src/features/estimate-generation/components/QuotaStatusNotice.tsx`
- Test: `prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx`

- [ ] Добавить контракт API с полями `limit`, `used`, `available`, `reservation_status`.
- [ ] Показать остаток AI-смет и объяснение при недоступном запуске без технических кодов.
- [ ] Проверить сценарии: лимит исчерпан, резерв создан, генерация завершилась технической ошибкой, готовая смета.
- [ ] Выполнить целевой Vitest, ESLint/Prettier изменённых файлов и `npx tsc --noEmit`.

