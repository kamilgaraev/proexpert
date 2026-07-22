# Изолированная доработка пакета AI-сметы: план реализации

> **Для агентных исполнителей:** ОБЯЗАТЕЛЬНЫЙ sub-skill: последовательно применять `superpowers:subagent-driven-development` или `superpowers:executing-plans`. В плане используются флажки `- [ ]`.

**Цель:** разрешить одну доказуемо изолированную доработку только выбранного пакета AI-сметы, не перегенерируя и не изменяя остальные пакеты.

**Архитектура:** новая ветка не вызывает `RebuildGeneratedSection`, `GenerateEstimateDraftJob` или `DraftPipelineEntrypoint`. Она строит copy-on-write замену одного пакета, проверяет канонические отпечатки всех остальных пакетов, отдельно пересчитывает только верхнеуровневые проекции и выполняет точечную запись с оптимистической блокировкой. Повторный вердикт арбитра получает сохранённую историю цикла и после одной доработки может только завершить проверку или передать смету человеку.

**Стек:** PHP 8.2, Laravel 11, PHPUnit 11, PHPStan, существующие `CanonicalPipelineJson`, нормировщик ресурсов и расчёт цен.

## Глобальные ограничения

- Во всех новых PHP-файлах использовать `declare(strict_types=1);` и PSR-12.
- Не изменять договоры, документы договоров, сервисы договоров и их тесты.
- Не вызывать и не импортировать `RebuildGeneratedSection`, `GenerateEstimateDraftJob`, `DraftPipelineEntrypoint`, `PipelineRunner`, `PublishValidatedDraft` или `syncFromDraft`.
- Нельзя создавать работы, объёмы, нормы, ресурсы, цены или доказательства без уже подтверждённого входного доказательства.
- Полный набор ресурсов выбранной нормы сохраняется; операция не вправе удалять самостоятельные ресурсы из готовой нормы.
- Кровля остаётся ограниченной доказательствами: покрытие не создаёт стропила, утеплитель, мембраны, обрешётку или водосток.
- При любой ошибке или несовпадении версии вернуть смету на проверку человеку и не менять черновик.
- Не запускать миграции, локальные команды БД, dev-серверы или frontend build.
- Каждый независимый этап заканчивается целевыми тестами, PHPStan, `git diff --check`, независимым ревью и коммитом Conventional Commit на русском с `[lk]`.

---

## Структура файлов

| Файл | Ответственность |
| --- | --- |
| `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageDraftPatcher.php` | Чистая copy-on-write замена одного пакета и контроль неизменности остальных. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackagePatchResult.php` | Типизированный результат с ключом пакета и отпечатками до/после. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageRebuildCommand.php` | Закрытый вход отдельной операции: версия, идентификаторы, доказательства и исходный хеш арбитра. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageRebuilder.php` | Мини-конвейер только для выбранного пакета; вызывает узкие нормировщик, сборщик ресурсов и расчёт цен. |
| `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/CommitTargetedPackageRebuild.php` | Транзакционная точечная публикация с optimistic lock и записью только одного пакета. |
| `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php` | Новый метод точечной синхронизации пакета, без обхода и истории остальных пакетов. |
| `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/Arbiter/ArbiterRemediationCoordinator.php` | Переход `recommended → attempted → reviewed`; повторная попытка запрещена. |
| `tests/Unit/EstimateGeneration/Application/TargetedRebuild/*` | Контракты copy-on-write, доказательств, ресурсов и идемпотентности. |
| `tests/Architecture/TargetedEstimateRebuildBoundaryTest.php` | Запрет старой полной пересборки и массовой синхронизации. |

### Task 1: Чистая copy-on-write граница пакета

**Файлы:**

- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageDraftPatcher.php`
- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackagePatchResult.php`
- Создать: `tests/Unit/EstimateGeneration/Application/TargetedRebuild/TargetedPackageDraftPatcherTest.php`

**Интерфейсы:**

```php
final readonly class TargetedPackagePatchResult
{
    /** @param array<string, string> $nonTargetFingerprints */
    public function __construct(
        public array $draft,
        public string $packageKey,
        public string $targetBeforeFingerprint,
        public string $targetAfterFingerprint,
        public array $nonTargetFingerprints,
    ) {}
}

final class TargetedPackageDraftPatcher
{
    public function replace(
        array $draft,
        string $expectedSourceInputVersion,
        string $packageKey,
        array $replacement,
    ): TargetedPackagePatchResult;
}
```

- [ ] **Шаг 1: Написать падающие unit-тесты.**

```php
#[Test]
public function it_replaces_only_the_named_package_and_preserves_other_canonical_fingerprints(): void
{
    $before = $this->draftWith('foundation', 'heating', 'ventilation');
    $replacement = $this->package('heating', 'revised-heating-work');

    $result = (new TargetedPackageDraftPatcher)->replace(
        $before,
        'sha256:'.str_repeat('a', 64),
        'heating',
        $replacement,
    );

    self::assertSame('revised-heating-work', $result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['key']);
    self::assertSame($this->fingerprint($before['local_estimates'][0]), $result->nonTargetFingerprints['foundation']);
    self::assertSame($this->fingerprint($before['local_estimates'][2]), $result->nonTargetFingerprints['ventilation']);
}

#[Test]
public function it_rejects_a_stale_source_version_or_non_unique_target_without_returning_a_draft(): void
{
    $patcher = new TargetedPackageDraftPatcher;

    $this->expectException(InvalidArgumentException::class);
    $patcher->replace($this->draftWith('heating', 'heating'), 'sha256:'.str_repeat('a', 64), 'heating', $this->package('heating', 'new'));
}
```

- [ ] **Шаг 2: Запустить RED-проверку.**

Запустить: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcherTest.php`

Ожидание: ошибка класса `TargetedPackageDraftPatcher`.

- [ ] **Шаг 3: Реализовать минимальный patcher.**

```php
$this->assertSourceVersion($draft, $expectedSourceInputVersion);
[$targetIndex, $before, $fingerprints] = $this->singleTarget($draft, $packageKey);
$this->assertPackageKey($replacement, $packageKey);
$draft['local_estimates'][$targetIndex] = $replacement;
$this->assertNonTargetsUnchanged($draft, $packageKey, $fingerprints);

return new TargetedPackagePatchResult(
    $draft,
    $packageKey,
    $this->fingerprint($before),
    $this->fingerprint($replacement),
    $fingerprints,
);
```

Отпечаток — `sha256:` плюс `hash('sha256', CanonicalPipelineJson::encode($package))`. Ключи всех пакетов должны быть уникальны и соответствовать `/^[A-Za-z0-9:._-]{1,120}$/`; `source_input_version` — только `sha256:<64 hex>`. Метод не меняет `arbiter_review`, итоговые суммы, статусы готовности или любой пакет кроме целевого.

- [ ] **Шаг 4: Запустить GREEN-проверку.**

Запустить: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcherTest.php`

Ожидание: PASS, включая пустой список, неизвестный пакет, дубликат, неверную версию и несовпадающий ключ replacement.

- [ ] **Шаг 5: Выполнить статическую проверку и коммит.**

```powershell
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcher.php app\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackagePatchResult.php --memory-limit=1G --no-progress
git diff --check
git add app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild tests/Unit/EstimateGeneration/Application/TargetedRebuild
git commit -m "feat[lk]: добавлена изолированная замена пакета AI-сметы"
```

### Task 2: Контракт одного завершённого цикла арбитра

**Файлы:**

- Создать: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/Arbiter/ArbiterRemediationState.php`
- Изменить: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/Arbiter/ArbiterRemediationCoordinator.php`
- Изменить: `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/Arbiter/ShadowArbiterCoordinator.php`
- Тест: `tests/Unit/EstimateGeneration/Quality/Arbiter/ArbiterRemediationCoordinatorTest.php`

**Интерфейсы:**

```php
final readonly class ArbiterRemediationState
{
    public function __construct(
        public string $rootInputHash,
        public array $targetPackageKeys,
        public bool $rebuildAttempted,
        public string $phase, // recommended|attempted|reviewed
        public ?string $reviewOutcome, // passed|confirmed_scope_only|human_review
    ) {}
}

public function markAttempted(array $draft, string $rootInputHash): array;
public function resolveAfterRebuild(array $draft, ArbiterVerdict $verdict): array;
```

- [ ] **Шаг 1: Добавить RED-тесты истории.**

```php
#[Test]
public function it_allows_one_attempt_then_keeps_the_root_cycle_when_the_second_review_passes(): void
{
    $attempted = $this->coordinator->markAttempted($this->recommendedDraft(), $this->hash());
    $resolved = $this->coordinator->resolveAfterRebuild($attempted, new ArbiterVerdict('passed', []));

    self::assertSame('reviewed', $resolved['arbiter_review']['remediation']['phase']);
    self::assertSame('passed', $resolved['arbiter_review']['remediation']['review_outcome']);
    self::assertTrue($resolved['arbiter_review']['remediation']['rebuild_attempted']);
}

#[Test]
public function it_turns_a_second_targeted_request_for_the_same_root_cycle_into_human_review(): void
{
    $result = $this->coordinator->resolveAfterRebuild($this->attemptedDraft(), $this->targetedVerdict());

    self::assertSame('human_review', $result['arbiter_review']['outcome']);
    self::assertSame('reviewed', $result['arbiter_review']['remediation']['phase']);
}
```

- [ ] **Шаг 2: Запустить RED-проверку.**

Запустить: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Quality\Arbiter\ArbiterRemediationCoordinatorTest.php`

Ожидание: методы и состояние отсутствуют.

- [ ] **Шаг 3: Реализовать отдельное состояние без изменения v1 `cycle`.**

`ArbiterReviewCycle::toArray()` остаётся обратносуместимым и по-прежнему выдаёт только свои пять ключей. Новое `arbiter_review.remediation` — единственное место истории операции. `markAttempted()` принимает только существующий `cycle` с `terminal_outcome=targeted_rebuild`, совпадающим `input_hash` и `attempted=false`; иначе возвращает `human_review` без изменения `local_estimates`. `resolveAfterRebuild()` всегда сохраняет `root_input_hash`; повторный `targeted_rebuild` при `rebuild_attempted=true` заменяется на `human_review`.

- [ ] **Шаг 4: Запустить GREEN-проверку и PHPStan.**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Quality\Arbiter\ArbiterRemediationCoordinatorTest.php tests\Unit\EstimateGeneration\Quality\Arbiter\ShadowArbiterCoordinatorTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter --memory-limit=1G --no-progress
```

Ожидание: PASS, повторная рекомендация по исходному циклу не создаёт новый автоматический запуск.

- [ ] **Шаг 5: Коммит.**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Services/Quality/Arbiter tests/Unit/EstimateGeneration/Quality/Arbiter
git commit -m "feat[lk]: ограничен цикл доработки пакета AI-сметы"
```

### Task 3: Изолированный нормировщик, ресурсы и цены целевого пакета

**Файлы:**

- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageRebuildCommand.php`
- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageRebuilder.php`
- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/TargetedPackageSummaryProjector.php`
- Тест: `tests/Unit/EstimateGeneration/Application/TargetedRebuild/TargetedPackageRebuilderTest.php`

**Интерфейсы:**

```php
final readonly class TargetedPackageRebuildCommand
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $expectedStateVersion,
        public string $sourceInputVersion,
        public string $operationId,
        public string $arbiterInputHash,
        public string $packageKey,
        public ArbiterVerdict $verdict,
        public string $sessionStatus,
        public array $draft,
    ) {}
}

final readonly class TargetedPackageRebuilder
{
    public function rebuild(TargetedPackageRebuildCommand $command): TargetedPackagePatchResult;
}
```

- [ ] **Шаг 1: Написать RED-тесты мини-конвейера.**

```php
#[Test]
public function it_prices_only_the_target_and_keeps_its_complete_norm_resources(): void
{
    $result = $this->rebuilder->rebuild($this->commandFor('heating'));

    self::assertSame('heating', $result->packageKey);
    self::assertSame(['water', 'pipe', 'labor', 'machine', 'operator'], $this->resourceKinds($result->draft, 'heating'));
    self::assertSame($this->fingerprint($this->draft['local_estimates'][0]), $result->nonTargetFingerprints['foundation']);
}

#[Test]
public function it_refuses_missing_evidence_before_norm_matching_or_price_calculation(): void
{
    $this->expectException(TargetedPackageEvidenceRequired::class);
    $this->rebuilder->rebuild($this->commandWithFinding('heating', []));
}
```

- [ ] **Шаг 2: Запустить RED-проверку.**

Запустить: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuilderTest.php`

Ожидание: отсутствуют command, executor и исключение доказательств.

- [ ] **Шаг 3: Реализовать узкий исполнитель.**

Исполнитель обязан:

1. Сверить `source_input_version`, ключ пакета, `arbiter_review.cycle.input_hash`, `arbiter_review.remediation` в фазе `attempted` и уже проверенный `ArbiterVerdict` из команды.
2. Извлечь только существующий пакет `packageKey`. Исполнитель не создаёт, не удаляет и не меняет состав work item: отсутствующий компонент остаётся основанием для ручной проверки, а не поводом выдумать объём.
3. Передать только work items этого пакета в `ResourceAssemblyService::enrich()`, затем в `AssembleMatchedResources` с synthetic single-package payload и в `EstimatePricingService::price()` с узким `PipelineContext`, построенным из идентификаторов команды. Каждый обрабатываемый work item должен иметь принятую quantity evidence без review blockers; иначе бросить `TargetedPackageEvidenceRequired` до выбора нормы или цены.
4. Заменить кандидат через `TargetedPackageDraftPatcher` и сформировать только безопасные верхнеуровневые агрегаты (`normative_matching`, `budget_scope`, `completeness`) отдельным проектором, не обходящим и не меняющим `local_estimates`. Нормировщик переносит полный состав ресурсов нормы; исполнитель не фильтрует ни один ресурс.

Не вызывать `PlanWorkItemsStage`, `EstimateValidationService::validate`, `BuildDraftStage`, `ValidateDraftStage` или full-pipeline persistence.

- [ ] **Шаг 4: Запустить GREEN-проверки.**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcherTest.php tests\Unit\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuilderTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild --memory-limit=1G --no-progress
```

Ожидание: PASS; проверка хешей подтверждает, что нецелевые пакеты идентичны.

- [ ] **Шаг 5: Коммит.**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild tests/Unit/EstimateGeneration/Application/TargetedRebuild
git commit -m "feat[lk]: добавлена точечная доработка пакета AI-сметы"
```

### Task 4: Точечная публикация и архитектурный запрет массовой пересборки

**Файлы:**

- Создать: `app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild/CommitTargetedPackageRebuild.php`
- Изменить: `app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php`
- Создать: `tests/Unit/EstimateGeneration/Application/TargetedRebuild/CommitTargetedPackageRebuildTest.php`
- Создать: `tests/Architecture/TargetedEstimateRebuildBoundaryTest.php`

**Интерфейсы:**

```php
public function commit(
    EstimateGenerationSession $session,
    TargetedPackageRebuildCommand $command,
    TargetedPackagePatchResult $result,
): EstimateGenerationSession;

public function syncPackageFromDraft(
    EstimateGenerationSession $session,
    string $packageKey,
    array $localEstimate,
    string $sourceInputVersion,
): void;
```

- [ ] **Шаг 1: Написать RED-тесты optimistic lock и точечной записи.**

```php
#[Test]
public function it_rejects_a_changed_state_version_before_the_package_writer_is_called(): void
{
    $this->expectException(StaleEstimateGenerationState::class);
    $this->commit->commit($this->sessionWithVersion(8), $this->commandWithVersion(7), $this->patchResult());
    self::assertSame([], $this->packages->syncedPackageKeys);
}

#[Test]
public function it_syncs_only_the_target_package_and_marks_the_cycle_attempted(): void
{
    $this->commit->commit($this->sessionWithVersion(7), $this->commandWithVersion(7), $this->patchResult());

    self::assertSame(['heating'], $this->packages->syncedPackageKeys);
    self::assertTrue($this->storedDraft()['arbiter_review']['remediation']['rebuild_attempted']);
}
```

- [ ] **Шаг 2: Запустить RED-проверку.**

Запустить: `vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild\CommitTargetedPackageRebuildTest.php tests\Architecture\TargetedEstimateRebuildBoundaryTest.php`

Ожидание: отсутствует точечный publisher, а архитектурная проверка не находит безопасный путь.

- [ ] **Шаг 3: Реализовать commit и архитектурный тест.**

`CommitTargetedPackageRebuild` загружает сессию через `lockForUpdate`, проверяет организацию, проект, `state_version`, `source_input_version`, ключ команды и хеши из `TargetedPackagePatchResult`. После `syncPackageFromDraft()` сохраняет новый `draft_payload`, увеличивает состояние существующим `AdvanceEstimateGeneration` и помечает `remediation.rebuild_attempted=true`. Он не вызывает `syncFromDraft`, `retainHistorical`, очередь и full pipeline.

Архитектурный тест читает только новые файлы TargetedRebuild и утверждает отсутствие строк `RebuildGeneratedSection`, `GenerateEstimateDraftJob`, `DraftPipelineEntrypoint`, `PublishValidatedDraft`, `syncFromDraft`, `dispatch(` и `onQueue(`.

- [ ] **Шаг 4: Запустить GREEN-проверки и независимое ревью.**

```powershell
vendor\bin\phpunit tests\Unit\EstimateGeneration\Application\TargetedRebuild tests\Architecture\TargetedEstimateRebuildBoundaryTest.php
vendor\bin\phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild app\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService.php --memory-limit=1G --no-progress
git diff --check
```

Ожидание: PASS; reviewer подтверждает, что новый путь не может выполнить массовую пересборку.

- [ ] **Шаг 5: Коммит.**

```powershell
git add app/BusinessModules/Addons/EstimateGeneration/Application/TargetedRebuild app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php tests/Unit/EstimateGeneration/Application/TargetedRebuild tests/Architecture/TargetedEstimateRebuildBoundaryTest.php
git commit -m "feat[lk]: ограничена публикация доработки AI-сметы"
```

## Самопроверка плана

**Покрытие спецификации:** Task 1 создаёт проверяемую copy-on-write границу. Task 2 не даёт потерять исходный цикл после второй проверки. Task 3 повторно строит только подтверждённый пакет и сохраняет ресурсы нормы. Task 4 делает точечную запись и запрещает прежнюю массовую ветку кодом и тестом.

**Проверка на заглушки:** у каждого этапа указаны точные файлы, интерфейсы, RED/GREEN-команды и коммит. Автоматическая доработка не разрешена до завершения всех четырёх этапов.

**Согласованность типов:** `TargetedPackageRebuildCommand` передаёт `sourceInputVersion`, `arbiterInputHash` и `packageKey` от арбитра к `TargetedPackageRebuilder`; `TargetedPackagePatchResult` передаёт тот же `packageKey` и отпечатки к точечной публикации.
