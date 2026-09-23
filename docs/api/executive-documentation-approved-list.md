# API ИД: перечень объекта и источники файлов

Все маршруты ниже находятся под `/api/v1/admin/executive-documentation`. Действуют существующие права ИД и проверка организации и объекта.

| Метод и путь | Назначение |
| --- | --- |
| `GET /projects/{projectId}/approved-lists` | Редакции утверждённого перечня объекта по убыванию |
| `POST /projects/{projectId}/approved-lists` | Новая редакция: `file`, `approved_at`, `approved_by_party`, `items[]` |
| `GET /projects/{projectId}/approved-lists/{listId}/download` | Временный URL файла перечня |
| `POST /sets/{set}/approved-list` | Применить `approved_list_id` и `item_keys[]` к черновику комплекта |
| `GET /versions/{versionId}/signature` | Временный URL отдельного файла подписи, если он есть |

Пункт перечня содержит `key`, `profile_type`, `title`, необязательные `stage`, `work_type_id`, `completed_work_id`, `project_location_id`. Ответ перечня содержит `revision`, `file_hash` (SHA-256), дату, сторону и исходное имя файла. `approved_list_id` возвращается с комплектом. Смена перечня создаёт новую запись; уже переданный комплект хранит прежний снимок в манифесте.

Существующий `POST /sets/{set}/documents` принимает прежний `initial_version[file]` или `source_warehouse_passport_file_id` для `quality_passport`. Одновременно оба источника не допускаются. Складской файл копируется в частное хранилище ИД; метаданные редакции содержат идентификаторы прихода и источника, а также SHA-256 исходника. Ограничение организации и объекта проверяется сервером.

`initial_version[file_kind]` и аналогичное поле новой редакции: `copy`, `paper_scan`, `electronic_original`. Для электронного файла необязателен `signature_file` (`sig`, `p7s`, `p7m`). Ответ редакции содержит `file_kind`, `has_detached_signature`, `signature_hash`. Это учёт файла, а не подтверждение действительности подписи.

`POST /sets/{set}/transmit` дополнительно принимает необязательный массив `paper_originals: [{document_id, copies_count}]`; количество допускается от 1 до 50 и записывается в неизменяемый манифест. Для старых вызовов поле не требуется. Подписи файлов и перечень включаются в пакет передачи с проверкой контрольных сумм.
