# План расширения ИИ ассистента: Взаимодействие с системой

## Цель
Расширить функциональность ИИ ассистента, чтобы он мог не только читать данные, но и активно взаимодействовать с системой - создавать, обновлять и управлять бизнес-объектами.

## Текущая архитектура (Read-only)
ИИ может только получать информацию через Actions:
- `GetProjectDetailsAction` - детали проекта
- `SearchContractsAction` - поиск контрактов
- `GenerateCustomReportAction` - генерация отчетов
- `CheckMaterialStockAction` - проверка остатков материалов

## Расширенная архитектура (Read + Write)

### 1. Write Actions архитектура

#### Базовый класс для Write Actions
```php
abstract class WriteAction
{
    protected $entity;
    protected $operation;

    abstract public function execute(int $organizationId, array $params): ActionResult;

    protected function validatePermissions(User $user, string $action, $entity = null): bool;

    protected function logAction(array $data): void;

    protected function rollback(): void;
}
```

#### Структура ActionResult
```php
class ActionResult
{
    public bool $success;
    public ?array $data;
    public ?string $error;
    public ?string $confirmation_required; // Для критичных операций
    public array $metadata = [];

    // Методы для работы с результатом
}
```

### 2. Категории Write Actions

#### 2.1 Проекты (Projects)
- `CreateProjectAction` - создание нового проекта
- `UpdateProjectAction` - обновление данных проекта
- `ArchiveProjectAction` - архивирование проекта
- `AssignProjectTeamAction` - назначение команды

#### 2.2 Контракты (Contracts)
- `CreateContractAction` - создание контракта
- `UpdateContractAction` - обновление условий
- `ApproveContractAction` - утверждение контракта
- `TerminateContractAction` - расторжение контракта

#### 2.3 Финансовые операции (Finance)
- `CreateActAction` - создание акта выполненных работ
- `ApproveActAction` - утверждение акта
- `CreateInvoiceAction` - выставление счета
- `RegisterPaymentAction` - регистрация оплаты

#### 2.4 Материалы (Materials)
- `UpdateMaterialStockAction` - обновление остатков
- `ReserveMaterialsAction` - резервирование материалов
- `OrderMaterialsAction` - заказ материалов

#### 2.5 Коммуникации (Communication)
- `SendNotificationAction` - отправка уведомлений
- `CreateTaskAction` - создание задач
- `UpdateTaskStatusAction` - обновление статуса задач

### 3. Система Intent Recognition

#### Новые Write Intents
```php
'create_project' => [
    'создай проект',
    'сделай новый проект',
    'заведи проект',
    'начни проект',
    'новый проект',
],

'update_contract' => [
    'измени условия контракта',
    'обнови договор',
    'поменяй сумму',
    'измени сроки',
    'корректировка контракта',
],

'approve_act' => [
    'утверди акт',
    'подпиши акт',
    'прими работу',
    'акт выполненных работ готов',
],
```

### 4. Система разрешений

#### Permission Checker
```php
class AIPermissionChecker
{
    public function canExecuteAction(User $user, string $action, array $params = []): bool;

    public function getRequiredRole(string $action): string;

    public function getActionScope(string $action): string; // project, contract, global
}
```

#### Уровни разрешений
- **Read** - только чтение (текущий уровень)
- **Write Basic** - базовые изменения (обновление данных)
- **Write Critical** - критичные операции (финансы, контракты)
- **Admin** - административные действия

### 5. Система подтверждений

#### Confirmation Manager
```php
class ConfirmationManager
{
    public function requiresConfirmation(string $action, array $params): bool;

    public function createConfirmationRequest(string $action, array $params, User $user): ConfirmationToken;

    public function executeWithConfirmation(string $token, User $user): ActionResult;
}
```

#### Типы подтверждений
- **User Confirmation** - подтверждение от пользователя
- **Supervisor Approval** - подтверждение руководителя
- **Two-Factor** - двухфакторная аутентификация

### 6. Транзакционная безопасность

#### Transaction Manager
```php
class AITransactionManager
{
    public function executeInTransaction(callable $action): ActionResult;

    public function rollbackOnError(): void;

    public function createBackup(string $entityType, int $entityId): BackupToken;
}
```

### 7. Расширение промптов ИИ

#### Новый системный промпт
```
Ты можешь не только предоставлять информацию, но и выполнять действия в системе:

СОЗДАНИЕ ОБЪЕКТОВ:
- "Создай новый проект 'Строительство дома' с бюджетом 5 млн руб"
- "Заведи контракт с подрядчиком 'ООО Ремонт' на сумму 500 тыс руб"

ОБНОВЛЕНИЕ ДАННЫХ:
- "Измени статус проекта №123 на 'Завершен'"
- "Обнови контактные данные подрядчика в контракте №456"

ФИНАНСОВЫЕ ОПЕРАЦИИ:
- "Утверди акт выполненных работ №789"
- "Зарегистрируй оплату счета №101 на 250 тыс руб"

Для критичных операций потребуется подтверждение пользователя.
```

### 8. Этапы реализации

#### Этап 1: Базовая инфраструктура
1. Создать базовые классы WriteAction и ActionResult
2. Реализовать систему разрешений AIPermissionChecker
3. Добавить логирование действий ИИ

#### Этап 2: Простые Write Actions
1. UpdateProjectAction (обновление не критичных данных)
2. UpdateContractAction (обновление условий)
3. CreateTaskAction (создание задач)

#### Этап 3: Критичные операции
1. CreateProjectAction с подтверждением
2. CreateContractAction с подтверждением
3. ApproveActAction с подтверждением руководителя

#### Этап 4: Финансовые операции
1. RegisterPaymentAction
2. CreateInvoiceAction
3. ApproveActAction

#### Этап 5: Интеграция и тестирование
1. Обновление промптов ИИ
2. Интеграционные тесты
3. Безопасность и аудит

### 9. Меры безопасности

#### 9.1 Аудит действий
- Все действия ИИ логируются с указанием пользователя
- Критичные операции требуют дополнительного подтверждения
- История изменений сохраняется для аудита

#### 9.2 Ограничения
- Ограничение количества операций в единицу времени
- Максимальная сумма финансовых операций без подтверждения
- Белый список разрешенных операций для каждого пользователя

#### 9.3 Мониторинг
- Мониторинг использования ИИ для выявления аномалий
- Алерты при подозрительной активности
- Регулярные аудиты действий ИИ

### 10. API изменения

#### Новые эндпоинты
```
POST /api/v1/ai-assistant/execute-action
{
  "action": "create_project",
  "params": {...},
  "confirmation_token": "optional"
}
```

#### Расширенный ответ чата
```json
{
  "conversation_id": 123,
  "message": {
    "id": 456,
    "role": "assistant",
    "content": "Проект создан успешно",
    "executed_action": {
      "type": "create_project",
      "result": {...},
      "requires_confirmation": false
    }
  }
}
```

## Риски и mitigation

### Риски
1. **Безопасность данных** - ИИ может случайно удалить важные данные
2. **Финансовые потери** - неправильные финансовые операции
3. **Нарушение бизнес-логики** - действия не соответствующие бизнес-правилам

### Mitigation стратегии
1. **Строгая система разрешений** - каждый action проверяется на права
2. **Подтверждения для критичных операций** - финансы, контракты, удаления
3. **Транзакции и бэкапы** - возможность отката изменений
4. **Ограничения и лимиты** - защита от злоупотреблений
5. **Аудит и мониторинг** - отслеживание всех действий

## Заключение
Расширение ИИ до активного взаимодействия с системой требует тщательного проектирования архитектуры безопасности и подтверждений. Начинать следует с простых операций, постепенно добавляя более сложные с соответствующими мерами защиты.
