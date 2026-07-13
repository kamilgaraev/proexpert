# Контракт production-среды CAD

МОСТ создаёт `CadConversionRuntime` только через `EstimateGenerationServiceProvider`. PDF-runtime остаётся отдельным контуром. Команда проверки готовности: `php artisan estimate-generation:cad-readiness --json`.

В production переменные `ESTIMATE_GENERATION_CAD_PYTHON`, `ESTIMATE_GENERATION_CAD_SCRIPT`, `ESTIMATE_GENERATION_CAD_DWGREAD`, `ESTIMATE_GENERATION_CAD_SANDBOX` и `ESTIMATE_GENERATION_CAD_REQUIREMENTS_LOCK` обязаны содержать абсолютные пути. Образ использует `/opt/geometry-venv/bin/python`, `/opt/libredwg/bin/dwgread` версии `0.13.4`, `/usr/local/bin/geometry-sandbox` и worker внутри `/var/www/html`. SHA-256 worker и lock-файла обязательны и сверяются readiness-проверкой.

Проверка сначала отклоняет отсутствующие файлы, ссылки и неисполняемые программы, и лишь затем запускает `dwgread --version` через argv-массив. В диагностике возвращаются только стабильные коды, без путей и вывода процессов. Лимиты времени, входа, выхода, сущностей, памяти, CPU, размера файлов и открытых файлов задаются отдельными числовыми переменными.

Repository replay fixtures включаются только через `ESTIMATE_GENERATION_REPOSITORY_REPLAY_ENABLED=true` в окружениях `local`/`testing` при наличии каталога fixtures. В production этот manifest и adapter не регистрируются.

Production benchmark objects сохраняются через `FileServiceBenchmarkPrivateObjectStore` в приватном S3 с immutable-контрактом; `ESTIMATE_GENERATION_BENCHMARK_PRODUCTION_OUTPUT_STORE=s3` фиксирует эксплуатационный backend. Локальный каталог отчётов предназначен только для локального/testing CLI и не считается production-хранилищем артефактов.
