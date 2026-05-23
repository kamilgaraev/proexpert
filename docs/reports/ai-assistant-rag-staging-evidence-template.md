# AI Assistant RAG Staging Evidence

Дата проверки: `YYYY-MM-DD`

Проверяющий: `name`

## Версии

- Backend commit/version: `git log -1 --oneline`
- Admin commit/version: `git log -1 --oneline`
- Environment: `staging`

## Конфигурация

Подтверждено без раскрытия секретов:

- `AI_RAG_ENABLED=true`
- `AI_RAG_EMBEDDING_PROVIDER=openai`
- `AI_RAG_EMBEDDING_MODEL=text-embedding-3-small`
- `AI_RAG_EMBEDDING_DIMENSIONS=1536`

## Backfill

Команда:

```bash
php artisan ai-assistant:rag-backfill {organization_id} --sync
```

Результат:

```text
Indexed RAG chunks: N
```

Критерий: `N > 0`.

## Grounded Answer

Пользователь: `user_id/email/role`

Проект: `project_id/name`

Вопрос:

```text
Что сейчас тормозит проект {project_name}?
```

Проверить и сохранить:

- ответ опирается на проектный контекст;
- `data.message.metadata.rag_context.used === true`;
- `data.message.metadata.rag_context.sources` содержит хотя бы один источник;
- UI показывает блок `Источники ответа`.

Фрагмент ответа API без секретов:

```json
{
  "data": {
    "message": {
      "metadata": {
        "rag_context": {
          "used": true,
          "sources": []
        }
      }
    }
  }
}
```

## Проверка Доступов

Пользователь без доступа: `user_id/email/role`

Проект с проиндексированными chunks: `project_id/name`

Проверить и сохранить:

- пользователь не имеет доступа к проекту;
- вопрос по проекту не возвращает chunks этого проекта в `rag_context.sources`;
- chunks этого проекта не попадают в prompt.

## Empty Context

Вопрос без релевантного RAG-контекста:

```text
{question}
```

Проверить и сохранить:

- `data.message.metadata.rag_context.used === false`;
- UI не показывает блок источников.

## Артефакты

- API-response с `rag_context.used=true` и sources: `path/link`
- API-response для пользователя без доступа: `path/link`
- API-response с `rag_context.used=false`: `path/link`
- Скриншот UI с блоком источников: `path/link`
- Скриншот UI без блока источников: `path/link`

## Итог

- [ ] Backfill выполнен и вернул `N > 0`.
- [ ] Grounded answer подтвержден.
- [ ] Изоляция проектных доступов подтверждена.
- [ ] Empty context подтвержден.
- [ ] Артефакты сохранены.
