# Production Normative Inventory

Дата проверки: 2026-06-29

## Итог

Read-only аудит production-норм из текущей Codex-среды не завершен: SSH-соединение до
`codex-ro@89.169.44.117` зависает или обрывается на этапе `banner exchange`.
Поэтому фактические production-счетчики норм, ресурсов и цен в этом документе пока не
заполнены. Это сознательное ограничение: нельзя подменять проверку предположениями.

Локальный код подтверждает, что доменная схема и сервисы уже рассчитаны на работу с
нормативной базой:

- `estimate_dataset_versions`
- `estimate_norm_collections`
- `estimate_norm_sections`
- `estimate_norms`
- `estimate_norm_resources`
- `estimate_resource_prices`
- `estimate_regional_price_versions`

## Проверка SSH

Команда:

```powershell
ssh -o BatchMode=yes -o ConnectTimeout=10 -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "echo ok"
```

Фактический результат из Codex-среды:

```text
Connection timed out during banner exchange
Connection to 89.169.44.117 port 22 timed out
```

## Команды для повторного read-only аудита

После восстановления SSH-доступности выполнить только read-only команды ниже.

### Количество записей

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode([
    \"dataset_versions\" => DB::table(\"estimate_dataset_versions\")->count(),
    \"norm_collections\" => DB::table(\"estimate_norm_collections\")->count(),
    \"norm_sections\" => DB::table(\"estimate_norm_sections\")->count(),
    \"norms\" => DB::table(\"estimate_norms\")->count(),
    \"norm_resources\" => DB::table(\"estimate_norm_resources\")->count(),
    \"resource_prices\" => DB::table(\"estimate_resource_prices\")->count(),
    \"regional_price_versions\" => DB::table(\"estimate_regional_price_versions\")->count(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

### Последние версии датасетов

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode(DB::table(\"estimate_dataset_versions\")->select(\"id\", \"source_type\", \"version_key\", \"status\", \"rows_imported\", \"created_at\")->orderByDesc(\"id\")->limit(20)->get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

### Покрытие по сборникам

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode(DB::table(\"estimate_norm_collections as c\")->join(\"estimate_norms as n\", \"n.collection_id\", \"=\", \"c.id\")->selectRaw(\"c.norm_type, c.code, count(*) as norms\")->groupBy(\"c.norm_type\", \"c.code\")->orderBy(\"c.code\")->get(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

### Связь норм, ресурсов и цен

```powershell
ssh -i "C:\Users\kamilgaraev\.ssh\codex_readonly" codex-ro@89.169.44.117 "codex-tinker --execute='echo json_encode([
    \"norms_with_resources\" => DB::table(\"estimate_norms as n\")->join(\"estimate_norm_resources as r\", \"r.estimate_norm_id\", \"=\", \"n.id\")->distinct(\"n.id\")->count(\"n.id\"),
    \"priced_resources\" => DB::table(\"estimate_resource_prices\")->whereNotNull(\"unit_price\")->count(),
    \"regional_priced_resources\" => DB::table(\"estimate_resource_prices\")->whereNotNull(\"regional_price_version_id\")->count(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'"
```

## Риски

- Без фактического production-аудита нельзя честно подтвердить процент покрытия норм ресурсами и ценами.
- Если `estimate_norms` заполнены, но `estimate_norm_resources` или `estimate_resource_prices` неполные, генератор должен отдавать `missing_resources` или `missing_prices`, а не рассчитанный `0 ₽`.
- Если региональные цены не активны для выбранного региона или периода, это должно быть явным blocker/status в мастере проверки.

## Решения

- Не блокировать реализацию no-air инвариантов из-за временной недоступности SSH.
- Не считать отсутствие production-счетчиков доказательством отсутствия норм.
- После восстановления SSH обновить этот документ фактическими числами и использовать их для метрик покрытия `norm match`, `priced line rate`, `missing price rate`.
