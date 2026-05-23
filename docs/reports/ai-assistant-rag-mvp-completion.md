# AI Assistant RAG MVP Completion Report

Дата: 2026-05-23

## Статус

Локальная реализация MVP RAG для AI-ассистента завершена в ветке `main` и проверена автоматическими gate. Открытым остается только внешний manual staging checklist из плана `docs/superpowers/plans/2026-05-23-rag-smart-assistant.md`: его нельзя доказательно закрыть без staging-деплоя, применения миграций, включения env-флага и backfill реальных данных.

## Реализовано

- Добавлены таблицы и модели `ai_rag_sources`, `ai_rag_chunks`.
- Добавлен embedding provider для OpenAI embeddings.
- Добавлены source collectors для проектов, графиков, договоров, закупок, склада, заявок участка, выполненных работ и project pulse.
- Добавлены `RagIndexer`, queue job и artisan-команда `ai-assistant:rag-backfill`.
- Добавлен permission-scoped retrieval с фильтрацией по организации, проекту и доступам пользователя.
- RAG-контекст подключен к основному потоку `AIAssistantService`.
- Metadata ответа ассистента включает `rag_context`.
- `AssistantResponseVerifier` защищает ответы от недоказанных claims по проектному контексту и чистит отсутствующие source refs.
- Админка нормализует `rag_context` и показывает источники только для grounded answers.
- Backend feature-тест проверяет оба режима: RAG включен с источниками и RAG выключен без попадания контекста в prompt.
- Container-тест проверяет, что Laravel service container внедряет `RagRetriever` и `RagPromptContextBuilder` в `AIAssistantService`.
- Feature-тест backfill-команды проверяет sync-индексацию и async-dispatch job в очередь `ai-rag`.
- Admin unit-тест проверяет, что блок источников видим только при `rag_context.used === true` и наличии sources.
- Encoding-test покрывает ключевые backend/admin файлы RAG-интеграции, включая admin page/helper и feature-тест RAG-контракта.

## Автоматическая валидация

Последняя локальная валидация после backend/admin test-коммитов:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php tests\Feature\Console\AIAssistantRagBackfillCommandTest.php
```

Результат: `OK (191 tests, 989 assertions)`.

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php
```

Результат: `OK (13 tests, 27 assertions)`.

```powershell
vendor\bin\phpstan analyse app/BusinessModules/Features/AIAssistant tests/Unit/AIAssistant tests/Feature/Api/V1/Admin/AIAssistantRagContextTest.php tests/Feature/Console/AIAssistantRagBackfillCommandTest.php --memory-limit=1G
```

Результат: `No errors`, `182/182`.

```powershell
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever.php
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer.php
php -l app\BusinessModules\Features\AIAssistant\Services\AIAssistantService.php
php -l app\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator.php
php -l tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php
php -l tests\Feature\Console\AIAssistantRagBackfillCommandTest.php
```

Результат: syntax OK для всех перечисленных файлов.

```powershell
cd ..\prohelper_admin
npx vitest run src/pages/AIAssistant/ragSources.test.ts src/services/aiAssistantService.test.ts
npx tsc --noEmit
cd ..\prohelper
```

Результат: Vitest `7 passed`, TypeScript `exit code 0`.

## Staging Checklist

Выполнять только на staging после деплоя соответствующих backend/admin коммитов и после явного разрешения на миграции/backfill.

1. Убедиться, что staging содержит backend-коммиты до `700909ca` включительно и admin-коммиты до `68d01983` включительно.
2. Применить миграции staging штатным deployment-процессом.
3. Включить RAG:

```env
AI_RAG_ENABLED=true
AI_RAG_EMBEDDING_PROVIDER=openai
AI_RAG_EMBEDDING_MODEL=text-embedding-3-small
AI_RAG_EMBEDDING_DIMENSIONS=1536
```

4. Выполнить backfill одной тестовой организации:

```bash
php artisan ai-assistant:rag-backfill {organization_id} --sync
```

Ожидаемый признак: команда выводит `Indexed RAG chunks: N`, где `N > 0`.

5. Задать в админке вопрос по рискам доступного проекта, например:

```text
Что сейчас тормозит проект {project_name}?
```

Ожидаемые признаки:

- ответ опирается на проектный контекст;
- `data.message.metadata.rag_context.used === true`;
- `data.message.metadata.rag_context.sources` содержит хотя бы один источник;
- UI показывает блок `Источники ответа`.

6. Проверить изоляцию проектных доступов:

- взять пользователя без доступа к проекту с проиндексированными chunks;
- задать вопрос по этому проекту;
- подтвердить, что chunks этого проекта не попали в `rag_context.sources` и prompt.

7. Проверить hidden state источников:

- задать вопрос, по которому нет релевантного RAG-контекста;
- подтвердить `rag_context.used === false`;
- подтвердить, что UI не показывает блок источников.

## Доказательство для закрытия плана

Manual staging checklist можно отметить выполненным только если сохранены следующие артефакты:

- staging commit/version backend и admin;
- вывод backfill-команды с `N > 0`;
- пример API-ответа с `rag_context.used=true` и sources;
- пример проверки пользователя без доступа к проекту;
- пример ответа без контекста с `rag_context.used=false`;
- скриншот или браузерная проверка админки с блоком источников и без него.

## Запреты

- Не запускать миграции локально без явной команды.
- Не выполнять backfill на production без отдельного deployment-процесса и разрешения.
- Не выводить секреты `.env`, токены и ключи провайдеров в отчеты.
- Не запускать `npm run build` для `prohelper_admin` и `prohelper_land`.
