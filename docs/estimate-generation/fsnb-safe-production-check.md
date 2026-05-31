# FSNB Safe Production Check

Документ описывает только read-only диагностику AI-генерации смет. Команды не должны менять production, запускать миграции, очищать кеши или перезапускать сервисы.

## Быстрая проверка сессии

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "cd /var/www/prohelper && APP_ENV=production LOG_CHANNEL=stderr php artisan estimate-generation:production-check --session_id=24"
```

Смотреть в отчете:

- `accepted` — строки, которые можно считать автоматически.
- `review_priced` — рассчитанные строки на проверку; после hard gates их должно быть мало, и они не должны содержать чужие домены.
- `candidate_only` и `not_found` — строки без безопасной цены.
- `unit_mismatch` и `scope_mismatch` — признаки того, что норму нельзя использовать для цены.
- `Рассчитанные строки с риск-флагами` — список, который должен быть пустым для безопасного черновика.
- `Learning examples` — количество обучающих примеров.
- `RAG source estimate_generation_learning` — есть ли индексированные chunks по learning source.

## JSON-отчет

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "cd /var/www/prohelper && APP_ENV=production LOG_CHANNEL=stderr php artisan estimate-generation:production-check --session_id=24 --json"
```

JSON удобен, если нужно сравнить два запуска или приложить компактный отчет к задаче.

## Проверка количества learning examples

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='echo DB::table(\"estimate_generation_learning_examples\")->count();'"
```

Если значение `0`, подбор работает без Estimate Memory. Для подготовки данных использовать backfill-команду только в штатном deployment/maintenance процессе, не из read-only production-доступа.

## Dry-run bootstrap на сервере

Dry-run не пишет данные, но показывает, сколько примеров можно извлечь:

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "cd /var/www/prohelper && APP_ENV=production LOG_CHANNEL=stderr php artisan estimates:generation-learning:bootstrap --organization_id=1 --limit=100"
```

Не добавлять `--write` в read-only SSH-сессии.

## Что считать проблемой

- Есть строки в `Рассчитанные строки с риск-флагами`.
- `review_priced` содержит кран, железную дорогу, бурение, взрывные работы, шпунтовое ограждение или водопроводную арматуру для работ другого домена.
- `max_line_total` несоразмерен объекту и связан с `review_priced`.
- `candidate_only` резко вырос после релиза без понятного изменения входных документов.
- `Learning examples` равен `0` при наличии импортированных смет с нормативными кодами.
- RAG source `estimate_generation_learning` не имеет chunks после штатной индексации.
