# FSNB-first генерация смет: production checklist

Все команды ниже read-only. Для production использовать только read-only SSH и `codex-tinker`:

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='echo DB::table(\"estimate_generation_sessions\")->count();'"
```

## 1. Последние сессии и статусы

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"estimate_generation_sessions\")->select(\"id\",\"project_id\",\"status\",\"processing_stage\",\"processing_progress\",\"updated_at\")->orderByDesc(\"id\")->limit(20)->get()->each(fn($r)=>print(json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## 2. Распределение статусов за сутки

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"estimate_generation_sessions\")->where(\"created_at\", \">=\", now()->subDay())->select(\"status\", DB::raw(\"count(*) as total\"))->groupBy(\"status\")->orderByDesc(\"total\")->get()->each(fn($r)=>print(json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## 3. Качество подбора ФСНБ по audit events

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='$rows=DB::table(\"estimate_generation_audit_events\")->where(\"event_type\",\"normative_decision_summary\")->where(\"created_at\", \">=\", now()->subDay())->get(); echo json_encode([\"packages\"=>$rows->count(),\"accepted\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"accepted\",0)),\"review_priced\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"review_priced\",0)),\"candidate_only\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"candidate_only\",0)),\"not_found\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"not_found\",0)),\"unit_mismatch\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"unit_mismatch\",0)),\"scope_mismatch\"=>$rows->sum(fn($r)=>(int)data_get(json_decode($r->payload,true),\"scope_mismatch\",0))], JSON_UNESCAPED_UNICODE);'"
```

## 4. Самые дорогие строки, которые требуют внимания

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"estimate_generation_package_items\")->select(\"package_id\",\"key\",\"name\",\"unit\",\"quantity\",\"normative_status\",\"total_cost\",\"flags\")->where(\"total_cost\", \">\", 1000000)->orderByDesc(\"total_cost\")->limit(20)->get()->each(fn($r)=>print(json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## 5. Candidate-only и mismatch по пакетам

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"estimate_generation_audit_events\")->where(\"event_type\",\"normative_decision_summary\")->orderByDesc(\"id\")->limit(50)->get()->each(fn($r)=>print(json_encode([\"session_id\"=>$r->session_id,\"package_id\"=>$r->package_id,\"payload\"=>json_decode($r->payload,true)], JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## 6. Estimate Memory по организациям

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"estimate_generation_learning_examples\")->select(\"organization_id\",\"source_type\", DB::raw(\"count(*) as total\"))->groupBy(\"organization_id\",\"source_type\")->orderByDesc(\"total\")->limit(30)->get()->each(fn($r)=>print(json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## 7. RAG index для `estimate_generation_learning`

```powershell
ssh -i C:\Users\kamilgaraev\.ssh\codex_readonly codex-ro@89.169.44.117 "codex-tinker --execute='DB::table(\"rag_chunks\")->select(\"source_type\", DB::raw(\"count(*) as total\"), DB::raw(\"max(updated_at) as last_update\"))->where(\"source_type\",\"estimate_generation_learning\")->groupBy(\"source_type\")->get()->each(fn($r)=>print(json_encode($r, JSON_UNESCAPED_UNICODE).PHP_EOL));'"
```

## Что считать тревожным

- `unit_mismatch > 0` среди строк с ненулевой ценой.
- `scope_mismatch > 0` среди строк с ненулевой ценой.
- `candidate_only` резко растет после импорта нового корпуса ФСНБ.
- `max_line_total` по пакету выше разумного порога для типа объекта.
- `not_found` выше 20-30% в типовых частных домах.
- Нет свежих `rag_chunks` для `estimate_generation_learning`, хотя импортированные сметы и правки уже есть.
