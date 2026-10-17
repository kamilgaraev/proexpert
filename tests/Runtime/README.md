# PostgreSQL-тесты

Запускайте DB-тесты через `run-postgres-tests.ps1` с каноническим `phpunit.xml`. Launcher использует PostgreSQL 16 и отдельную базу `most_phpunit_<random>_testing` для каждого PHPUnit-процесса.

```powershell
./tests/Runtime/run-postgres-tests.ps1 -TestPath tests/Feature/Infrastructure/PhpUnitPostgresProfileTest.php
./tests/Runtime/run-postgres-tests.ps1 -IsolatedAiAssistant -TestPath tests/Feature/AIAssistant/AssistantReadConcurrencyLimiterTest.php
```

Штатные проекты — `most-postgres-tests` на `127.0.0.1:55433` и `most-ai-assistant-tests` на `127.0.0.1:55443`. Если блокировка, существующий контейнер или порт заняты, launcher создаёт проект с уникальным суффиксом, а Docker назначает свободный loopback-порт. Launcher выводит выбранные проект и порт. Ожидание другого агента и пересоздание чужого контейнера не требуются.

После завершения, включая ошибку тестов или запуска, launcher останавливает и удаляет контейнеры, сеть и volume только своего проекта. Если Docker не смог выполнить очистку, launcher возвращает ошибку и сохраняет временный Compose-файл для восстановления. Временные Compose-файлы и опциональные PHPUnit event logs размещаются в системном TEMP.

PHPUnit получает выбранный порт через `MOST_POSTGRES_TEST_PORT` и имя проекта через `MOST_POSTGRES_TEST_PROJECT`. Bootstrap проверяет профиль, имя проекта, loopback-адрес и тестовое имя базы. Дочерним PHP-процессам с явно заданным окружением передавайте оба значения вместе с `MOST_POSTGRES_TEST_PROFILE`; порт в `DB_PORT` должен совпадать с выбранным launcher.

Конкурентная отгрузка проверяется отдельно в `ProjectMaterialDeliveryConcurrencyTest`: ей нужны Linux PHP с `pcntl` и `posix` и зафиксированные до запуска дочерних процессов данные. Windows пропускает только этот тест. В Linux он запускается через тот же launcher и канонический `phpunit.xml`.

Проверка выбора проекта, передачи порта и очистки при сбоях без реальной БД:

```powershell
pwsh -NoProfile -File tests/Runtime/test-postgres-launcher.ps1
```
