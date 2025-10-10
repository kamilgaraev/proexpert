# Отладка AI Assistant

## Проблема
AI говорит "у меня нет доступа" даже когда данные должны быть.

## Как проверить

### 1. Смотрим логи в реальном времени:

```bash
tail -f storage/logs/laravel.log | grep -E "(ai\.intent|ai\.action|ai\.context)"
```

### 2. После запроса "Какие у нас есть контракты?" должны увидеть:

```json
[INFO] ai.intent.recognized {
  "intent": "contract_search",  ← Должен быть contract_search, а не general!
  "query": "Какие у нас есть контракты?"
}

[INFO] ai.action.executed {
  "action": "SearchContractsAction",
  "intent": "contract_search",
  "params": {...},
  "result_keys": ["total", "contracts", "by_status"],  ← Должны быть ключи!
  "has_data": true  ← Должно быть true!
}

[INFO] ai.context.built {
  "intent": "contract_search",
  "context_keys": ["intent", "organization", "contract_search"],  ← Должен быть contract_search!
  "has_action_data": true  ← Должно быть true!
}
```

### 3. Что может быть не так:

#### A) Intent распознается неправильно
```json
"intent": "general"  ← ПЛОХО! Должен быть contract_search
```
**Решение**: Проверить IntentRecognizer, добавить больше паттернов

#### B) Action не возвращает данные
```json
"has_data": false,  ← ПЛОХО!
"result_keys": []
```
**Решение**: Проверить что в БД есть контракты, проверить Action

#### C) Action выдает ошибку
```json
[ERROR] ai.action.error {
  "error": "..."  ← Здесь будет текст ошибки
}
```
**Решение**: Исправить ошибку в Action

### 4. Полная команда для отладки:

```bash
# Последние 200 строк лога, только AI
tail -200 storage/logs/laravel.log | grep -A 3 -E "(ai\.intent|ai\.action|ai\.context)"

# Или с цветами (если установлен jq):
tail -200 storage/logs/laravel.log | grep "ai\." | jq -C .
```

### 5. Проверка БД напрямую:

```bash
# Проверяем есть ли контракты
php artisan tinker
>>> DB::table('contracts')->count()
>>> DB::table('contracts')->where('organization_id', 6)->get()
```

## Возможные причины проблемы:

1. **Нет контрактов в БД** - Action возвращает пустой список
2. **Intent распознается как "general"** - Action вообще не вызывается
3. **Ошибка в SQL запросе** - Action падает с ошибкой
4. **Проблема с organization_id** - Ищет в неправильной организации
5. **Данные есть, но не форматируются** - Проблема в formatContextForLLM

## Быстрая проверка:

```bash
php artisan tinker

# Проверяем что Intent распознается
>>> $recognizer = app(\App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer::class);
>>> $recognizer->recognize('Какие у нас есть контракты?', null);
// Должно вернуть: "contract_search"

# Проверяем что Action работает
>>> $action = app(\App\BusinessModules\Features\AIAssistant\Actions\Contracts\SearchContractsAction::class);
>>> $action->execute(6, []);  // 6 - ваш organization_id
// Должно вернуть массив с контрактами
```

