# Приём и активация публикаций по коду

Выпуск принимается только как каноническая пара `proof` и подписанного CI-artifact из заданного trusted directory. Пути, симлинки, имя пары, JSON-каноничность, подпись, issuer provenance, subject, code, commit, CI run, timestamp, digest suite и упорядоченный набор checks проверяются до вызова registry.

Promotion остаётся идемпотентным в `ReportPublicationRegistry`: повтор допустим только для того же proof; отличающийся proof для активного code отклоняется. Режим `canary` или `on` конфигурируется только после успешного `promote`; массового пути активации нет.

Runtime registry теперь materializes только current DB publication с совпадающими `publication_id` и `proof_sha256` в feature configuration и режимом `on`. YAML manifest больше не является authority для runtime catalog; digest каталога строится из DB publication identities. Transactional outbox миграции публикационного реестра остаётся контрактом инвалидации/доставки состояния.

Не добавлялись R15 candidate/request/evidence/provider, публикация R15, Procurement, R07 или Admin. Для конкретного отчёта всё ещё нужны его source/formula/provider/export/RBAC proof, зарегистрированная admission и CI artifact; этот общий слой сам по себе не означает готовность R15 к публикации.
