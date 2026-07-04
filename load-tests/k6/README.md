# МОСТ k6 load tests

Набор предназначен для аккуратной проверки production API после запуска `ProductionLoadTestSeeder`.
Сценарий `most-admin-readonly.js` делает только логин и read-only запросы к admin API.

## Что проверяет

- JWT login через `/api/v1/admin/auth/login`.
- Профиль, дашборд и список проектов.
- Карточки проектов, статистику, материалы, контракты, графики и аналитику.
- Заявки с объекта, склады и платежные документы.

Логин выполняется в `setup()`, чтобы не упереться в строгий auth throttle и не превратить тест API в тест защиты от перебора пароля.

## Быстрый запуск из PowerShell

```powershell
cd C:\Users\kamilgaraev\Desktop\prohelper_full\prohelper

$env:BASE_URL = "https://api.1мост.рф"
$env:PROFILE = "smoke"
$env:K6_SUMMARY_JSON = "load-tests/k6/results/prod-smoke.json"

New-Item -ItemType Directory -Force load-tests/k6/results | Out-Null
k6 run .\load-tests\k6\most-admin-readonly.js
```

После `smoke` можно запускать рабочий профиль:

```powershell
$env:PROFILE = "baseline"
$env:K6_SUMMARY_JSON = "load-tests/k6/results/prod-baseline.json"
k6 run .\load-tests\k6\most-admin-readonly.js
```

## Профили

| PROFILE | Назначение | Длительность |
| --- | --- | --- |
| `smoke` | Проверить, что авторизация и основные ручки живые | 2 минуты |
| `baseline` | Примерно живой режим для 5-6 компаний | 11 минут |
| `peak` | Короткий пик активности | 12 минут |
| `stress` | Поиск предела, запускать только после baseline | 16 минут |
| `soak` | Долгая стабильность и утечки ресурсов | 65 минут |

## Переменные

| Переменная | Значение по умолчанию | Описание |
| --- | --- | --- |
| `BASE_URL` | обязательна | Домен production без `/api`, например `https://example.com` |
| `PROFILE` | `smoke` | Один из профилей выше |
| `LOAD_TEST_PASSWORD` | `LoadTest123!` | Пароль пользователей из сидера |
| `ACCOUNT_LABELS` | `owner` | Какие типы аккаунтов использовать |
| `LOGIN_PATH` | `/api/v1/admin/auth/login` | Путь логина admin API |
| `K6_SUMMARY_JSON` | пусто | Куда сохранить JSON-отчет |
| `FAIL_RATE` | `0.02` | Максимальная доля неуспешных HTTP-запросов |
| `P95_MS` | `1000` | Порог p95 по длительности запроса |
| `P99_MS` | `2500` | Порог p99 по длительности запроса |
| `CHECK_RATE` | `0.98` | Минимальная доля успешных проверок |
| `THINK_TIME_MIN` | `1` | Минимальная пауза пользователя между итерациями, сек |
| `THINK_TIME_MAX` | `3` | Максимальная пауза пользователя между итерациями, сек |
| `ALLOWED_STATUSES` | `200,204,304` | HTTP-статусы, которые считаются успешными |
| `LOG_FAILURES` | `true` | Выводить короткие ошибки по неуспешным запросам |
| `INCLUDE_SITE_REQUEST_LIST` | `false` | Включить список заявок с объекта |
| `INCLUDE_PROJECT_CONTRACT_LIST` | `false` | Включить список контрактов проекта |

## Рекомендуемый порядок

1. Запустить `smoke` и убедиться, что нет 401/403/404/500.
2. Запустить `baseline`; это основной ориентир для текущего объема данных.
3. Если `baseline` чистый, запустить `peak`.
4. `stress` и `soak` запускать отдельно, когда понятно, что прод не трогают реальные пользователи.

Хороший первый ориентир: `http_req_failed` ниже 2%, `checks` выше 98%, `http_req_duration.p95` до 1 секунды. Если появляется много `429`, это уже не падение сервера, а срабатывание rate limiter.

По умолчанию берутся 6 owner-аккаунтов, по одному на каждую компанию. Это гарантированно укладывается в лимит логина 20 запросов в минуту с одного IP. Если нужно отдельно проверить другие роли, через минуту после первого запуска можно поставить `ACCOUNT_LABELS=admin,pm` или `ACCOUNT_LABELS=accountant`.

Во время первого production smoke список заявок с объекта и список контрактов проекта вернули 500. Эти ручки оставлены за отдельными флагами, чтобы baseline мог измерять производительность стабильных read-only сценариев. После диагностики можно включить `INCLUDE_SITE_REQUEST_LIST=true` и `INCLUDE_PROJECT_CONTRACT_LIST=true`.

Summary дополнительно выводит топ медленных endpoint по p95. Полные метрики сохраняются в `K6_SUMMARY_JSON`, если переменная задана.
