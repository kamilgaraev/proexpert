# API Отчеты актов выполненных работ

Система генерации и управления отчетами актов выполненных работ в форматах PDF и Excel с автоматическим хранением на S3.

## Обзор функций

- ✅ Создание отчетов в форматах PDF и Excel
- ✅ Автоматическая загрузка файлов на S3 
- ✅ Автоматическое удаление файлов через месяц
- ✅ Получение временных ссылок для скачивания
- ✅ Фильтрация отчетов по различным критериям
- ✅ Перегенерация отчетов
- ✅ Детальная статистика по отчетам

## Endpoints

### 1. Получение списка отчетов

```http
GET /api/v1/admin/act-reports
```

**Query параметры:**
- `performance_act_id` (int, optional) - ID акта выполненных работ
- `format` (string, optional) - Формат отчета (pdf|excel)
- `date_from` (date, optional) - Дата создания от (Y-m-d)
- `date_to` (date, optional) - Дата создания до (Y-m-d)

**Пример запроса:**
```bash
curl -X GET "https://api.example.com/api/v1/admin/act-reports?format=pdf&date_from=2024-01-01" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Ответ:**
```json
{
  "data": {
    "reports": [
      {
        "id": 1,
        "report_number": "ACT-20240115-0001",
        "title": "Акт выполненных работ №12 от 15.01.2024",
        "format": "pdf",
        "file_size": "245.67 KB",
        "is_expired": false,
        "download_url": "https://s3.example.com/act_reports/1/ACT-20240115-0001_...",
        "expires_at": "2024-02-15 10:30:00",
        "created_at": "2024-01-15 10:30:00",
        "updated_at": "2024-01-15 10:30:00",
        "metadata": {
          "contract_id": 5,
          "contract_number": "DOG-2024-001",
          "project_name": "Строительство торгового центра",
          "contractor_name": "ООО Строй",
          "act_date": "2024-01-15",
          "act_amount": 500000.00,
          "works_count": 5
        },
        "performance_act": {
          "id": 12,
          "act_document_number": "АКТ-001",
          "act_date": "2024-01-15",
          "amount": 500000.00,
          "is_approved": true,
          "contract": {
            "id": 5,
            "contract_number": "DOG-2024-001",
            "project": {
              "id": 3,
              "name": "Строительство торгового центра"
            },
            "contractor": {
              "id": 2,
              "name": "ООО Строй"
            }
          }
        }
      }
    ],
    "statistics": {
      "total_reports": 15,
      "pdf_reports": 8,
      "excel_reports": 7,
      "expired_reports": 2,
      "total_file_size": "12.45 MB"
    }
  },
  "message": "Отчеты актов получены успешно"
}
```

### 2. Создание отчета

```http
POST /api/v1/admin/act-reports
```

**Тело запроса:**
```json
{
  "performance_act_id": 12,
  "format": "pdf",
  "title": "Акт выполненных работ №12 от 15.01.2024"
}
```

**Поля:**
- `performance_act_id` (int, required) - ID акта выполненных работ
- `format` (string, required) - Формат отчета (pdf|excel)
- `title` (string, optional) - Заголовок отчета (по умолчанию генерируется автоматически)

**Пример запроса:**
```bash
curl -X POST "https://api.example.com/api/v1/admin/act-reports" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "performance_act_id": 12,
    "format": "pdf"
  }'
```

**Ответ:**
```json
{
  "data": {
    "id": 16,
    "report_number": "ACT-20240115-0016",
    "title": "Акт выполненных работ №12 от 15.01.2024",
    "format": "pdf",
    "file_size": "245.67 KB",
    "is_expired": false,
    "download_url": "https://s3.example.com/act_reports/1/ACT-20240115-0016_...",
    "expires_at": "2024-02-15 14:20:00",
    "created_at": "2024-01-15 14:20:00",
    "updated_at": "2024-01-15 14:20:00",
    "metadata": {
      "contract_id": 5,
      "contract_number": "DOG-2024-001",
      "project_name": "Строительство торгового центра",
      "contractor_name": "ООО Строй",
      "act_date": "2024-01-15",
      "act_amount": 500000.00,
      "works_count": 5
    }
  },
  "message": "Отчет акта создан успешно"
}
```

### 3. Получение отчета по ID

```http
GET /api/v1/admin/act-reports/{id}
```

**Пример запроса:**
```bash
curl -X GET "https://api.example.com/api/v1/admin/act-reports/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Ответ:** Аналогичен структуре объекта из списка отчетов.

### 4. Получение ссылки для скачивания

```http
GET /api/v1/admin/act-reports/{id}/download
```

**Пример запроса:**
```bash
curl -X GET "https://api.example.com/api/v1/admin/act-reports/1/download" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Ответ:**
```json
{
  "data": {
    "download_url": "https://s3.example.com/act_reports/1/ACT-20240115-0001_...",
    "filename": "ACT-20240115-0001_akt-vypolnennykh-rabot-12-ot-15-01-2024_2024-01-15_10-30-00.pdf",
    "file_size": "245.67 KB",
    "expires_at": "2024-02-15 10:30:00"
  },
  "message": "Ссылка на скачивание получена успешно"
}
```

### 5. Перегенерация отчета

```http
POST /api/v1/admin/act-reports/{id}/regenerate
```

**Пример запроса:**
```bash
curl -X POST "https://api.example.com/api/v1/admin/act-reports/1/regenerate" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Ответ:**
```json
{
  "data": {
    "id": 1,
    "report_number": "ACT-20240115-0001",
    "title": "Акт выполненных работ №12 от 15.01.2024",
    "format": "pdf",
    "file_size": "247.23 KB",
    "is_expired": false,
    "download_url": "https://s3.example.com/act_reports/1/ACT-20240115-0001_...",
    "expires_at": "2024-02-15 15:45:00",
    "created_at": "2024-01-15 10:30:00",
    "updated_at": "2024-01-15 15:45:00"
  },
  "message": "Отчет акта перегенерирован успешно"
}
```

### 6. Удаление отчета

```http
DELETE /api/v1/admin/act-reports/{id}
```

**Пример запроса:**
```bash
curl -X DELETE "https://api.example.com/api/v1/admin/act-reports/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Ответ:**
```json
{
  "message": "Отчет акта удален успешно"
}
```

## Форматы отчетов

### PDF отчет
- Структурированный документ с таблицей работ
- Информация о контракте, проекте, подрядчике
- Детали по каждой работе и использованным материалам
- Места для подписей
- Автоматическая генерация даты создания

### Excel отчет
- Табличный формат с возможностью дальнейшей обработки
- Отдельные колонки для всех параметров работ
- Информация о материалах в отдельной колонке
- Форматирование чисел и дат

## Автоматическая очистка

Система автоматически удаляет просроченные отчеты (старше месяца) ежедневно в 03:00 с помощью команды:

```bash
php artisan act-reports:cleanup
```

Для тестирования можно использовать флаг `--dry-run`:

```bash
php artisan act-reports:cleanup --dry-run
```

## Права доступа

Для работы с отчетами актов требуются следующие разрешения:
- `view-act-reports` - просмотр отчетов
- `create-act-reports` - создание отчетов
- `update-act-reports` - перегенерация отчетов
- `delete-act-reports` - удаление отчетов

## Коды ошибок

| Код | Описание |
|-----|----------|
| 200 | Успешно |
| 201 | Отчет успешно создан |
| 404 | Отчет не найден |
| 410 | Срок действия отчета истек |
| 422 | Ошибки валидации |
| 500 | Ошибка сервера при генерации отчета |

## Ограничения

- Максимальное время жизни отчета: 30 дней
- Поддерживаемые форматы: PDF, Excel
- Файлы хранятся только на S3
- Доступ к отчетам только в рамках организации 