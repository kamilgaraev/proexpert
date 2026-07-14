# Automatic FSNB Promotion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Автоматически выбирать последнюю пригодную версию ФСНБ для новых сессий AI-сметчика без настройки `.env`.

**Architecture:** Источник нормативных данных возвращает последнюю завершённую безошибочную непустую версию ФСНБ. Политика фиксации использует её при создании сессии и по-прежнему отклоняет явно запрошенную несовпадающую версию.

**Tech Stack:** PHP 8.2, Laravel 11, PHPUnit, Eloquent.

## Global Constraints

- Не изменять обычные сметы.
- Не выбирать незавершённые, пустые или ошибочные импорты.
- Не менять ФСНБ внутри уже созданной AI-сессии.

---

### Task 1: Автоматический выбор ФСНБ

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/ApprovedNormativeDatasetLookup.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentApprovedNormativeDatasetLookup.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeDatasetPinPolicy.php`
- Modify: `tests/Unit/EstimateGeneration/Normatives/NormativeDatasetPinPolicyTest.php`

**Interfaces:**
- Produces: `ApprovedNormativeDatasetLookup::latestApprovedVersion(): ?string`
- Consumes: `EstimateDatasetVersion` со статусом `parsed` и успешной статистикой импорта.

- [x] **Step 1:** Добавить тест выбора последней рабочей версии и отказа при её отсутствии.
- [x] **Step 2:** Запустить точечный PHPUnit-тест и подтвердить ожидаемое падение.
- [x] **Step 3:** Реализовать `latestApprovedVersion()` и перевести политику фиксации на автоматический выбор.
- [x] **Step 4:** Запустить точечный PHPUnit-тест, `php -l` изменённых PHP-файлов и точечный PHPStan.
- [ ] **Step 5:** Закоммитить и отправить изменение в `main`.
