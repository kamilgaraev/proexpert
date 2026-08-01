# Publication gate: финальная доверенная граница

## Результат

Publication gate остаётся pre-provider и fail-closed. Этот блок не регистрирует отчёты, не меняет каталог, маршруты, UI или runtime bindings.

Закрыты финальные замечания trust-boundary review:

- безусловный выпуск артефакта из тестового proof удалён; в репозитории сейчас нет зарегистрированной production-заявки, поэтому штатный `main` workflow успешно завершается без signing job, protected environment, секрета, артефакта и upload;
- discovery вынесен в отдельный job без environment и секретов; защищённый signing job запускается только при наличии JSON-заявок после зелёного PostgreSQL publication contract;
- заявка является строгим неисполняемым JSON-контрактом и загружается только из доверенного каталога: PHP-файлы, `require`, symlink, path traversal, лишние поля и несовпадение имени файла с request ID отклоняются;
- JSON-заявка содержит только идентификатор. Реальные candidate definition, binding, conformance/delivery evidence, proof template, verified checks и исходные bytes candidate/official manifests предоставляет доверенный project registry, а не вызывающая сторона;
- public issuer до signer отклоняет sentinel proof и компоненты из `Tests\`; manifest hashes пересчитываются из фактических bytes, а список passed checks берётся только из доверенного admission и обязан точно совпадать с обязательными контрактами;
- подписанный bundle возвращается и записывается только после полной проверки `ReportPublicationEligibilityService` на фактических candidate, binding, conformance, delivery, manifest и release данных;
- имена bundle валидируются, запись выполняется эксклюзивно и существующий artifact/proof с тем же именем никогда не перезаписывается;
- Ed25519-артефакт сохраняет точную привязку candidate manifest, official manifest, candidate definition, proof, binding, conformance evidence, release SHA, approver и GitHub CI provenance;
- publication, disable, feature configuration и outbox delivery выполняются только через разграниченные PostgreSQL admission functions; runtime не имеет прямого DML и owner membership;
- DB constraints fail-closed связывают proof contract/release/approver с типизированными колонками и exact release artifact; `NULL`/`UNKNOWN` и неверные JSON-типы не проходят;
- `SECURITY DEFINER` использует public-qualified relations и безопасный `search_path`, где `pg_temp` стоит последним;
- PostgreSQL CI содержит отдельные регрессии для non-superuser issuer и operator: issuer не может перенаправить registry через временные таблицы, operator выполняет configure/disable только через admission functions, не имеет owner/issuer membership, прямого DML и `SET ROLE owner`;
- future-dated release отклоняется, emergency disable получает монотонное время, повторный disable возвращает доменную ошибку;
- штатный publication CI запускает unit/schema contracts до изолированного PostgreSQL suite и не допускает успешный skip.

## TDD и проверки

RED был подтверждён для безусловной подписи тестового proof, самодекларированных passed checks и workflow, который входил в protected environment без реальной заявки. После исправления граница fail-closed.

Финальный DB-free набор проверен по файлам, чтобы не дублировать долгие наборы:

- publication/proof PHPUnit: 115 тестов, 659 assertions;
- целевой release trust regression входит в этот набор: 15 тестов, 197 assertions;
- targeted PHPStan: 17 файлов, без ошибок;
- PHP syntax: 17 файлов, без ошибок;
- Pint: 17 scoped-файлов, замечаний после исправления нет;
- scoped `git diff --check`: чисто.

Локально не запускались PostgreSQL, миграции, авторизационные smoke-тесты, dev-server и frontend build. Новые issuer temporary-shadow и operator regressions остаются обязательным PostgreSQL CI evidence на точном commit SHA.

## Неизменённая граница

Provider activation, service-provider registration, report definitions, routes, admin UI, published catalog и включение feature mode `on` в этот блок не входят.

До provider activation deployment обязан настроить protected environment `report-publication-release` с matching signing key и выдать отдельным non-superuser DB principals только issuer/operator/runtime/outbox memberships. Общий superuser или owner membership запрещён.
