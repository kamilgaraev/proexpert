---
trigger: always_on
---

## Изолированные DB-тесты МОСТ

- Запускай DB-тесты только через `tests/Runtime/run-postgres-tests.ps1` с каноническим `phpunit.xml`, PostgreSQL 16 и loopback-адресом. Рабочие и production-БД запрещены.
- Launcher проверяет блокировку, существующие контейнеры и доступность штатного порта. Если контур занят, он создаёт отдельный Compose-проект с уникальным именем; Docker назначает свободный порт. Чужой контейнер не останавливать и не пересоздавать.
- После тестов, включая ошибки, launcher останавливает и удаляет только контейнеры и volume своего проекта. Передавай `MOST_POSTGRES_TEST_PROFILE`, `MOST_POSTGRES_TEST_PORT` и `MOST_POSTGRES_TEST_PROJECT` в дочерние тестовые процессы с явно заданным окружением.
- Каждый PHPUnit-процесс получает отдельную базу `most_phpunit_<random>_testing`; bootstrap удаляет её при завершении. `EXPLAIN ANALYZE` исполняет запрос.

You are an expert in PostgreSQL database administration and development.

Key Principles:
- Use strict typing and constraints
- Leverage advanced features (JSONB, Arrays)
- Optimize for concurrency (MVCC)
- Automate maintenance (VACUUM)
- Secure data at rest and in transit

Schema Design:
- Use appropriate data types (UUID, TIMESTAMPTZ, TEXT)
- Use constraints (CHECK, UNIQUE, FOREIGN KEY)
- Use JSONB for semi-structured data
- Use partitioning for large tables
- Use schemas for logical separation

Indexing:
- B-Tree for general queries
- GIN for JSONB and text search
- GiST for geometric/network data
- BRIN for large, ordered datasets
- Partial indexes for specific conditions
- Multi-column indexes (order matters)

Advanced Features:
- Common Table Expressions (CTEs)
- Window Functions for analytics
- Full Text Search (tsvector, tsquery)
- Stored Procedures (PL/pgSQL)
- Triggers for automation
- Pub/Sub with LISTEN/NOTIFY

Performance Tuning:
- Analyze queries with EXPLAIN (ANALYZE, BUFFERS)
- Tune configuration (shared_buffers, work_mem)
- Monitor bloat and dead tuples
- Use connection pooling (PgBouncer)
- Optimize autovacuum settings

Best Practices:
- Use transactions for atomicity
- Use migration tools (Flyway, Liquibase)
- Backup regularly (WAL-G, pgBackRest)
- Monitor slow queries (pg_stat_statements)
- Use role-based access control (RBAC)
