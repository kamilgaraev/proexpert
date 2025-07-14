# Поля проекта (Admin UI)

| Поле | Тип | Описание |
|------|-----|----------|
| name | string | Название проекта |
| address | string | Адрес строительной площадки |
| customer | string | Заказчик проекта |
| designer | string | Проектировщик / автора проекта |
| budget_amount | number (₽) | Бюджет проекта (сметная стоимость) |
| site_area_m2 | number | Площадь строительной площадки, м² |
| contract_number | string | Номер контракта / договора |
| description | string | Описание, примечания |
| start_date | date | Плановая дата начала |
| end_date | date | Плановая дата окончания |
| status | enum | active, completed, paused, cancelled |
| is_archived | boolean | Архивный проект (не отображается в списках по умолчанию) |
| external_code | string | Внешний код в ERP/1С |
| cost_category_id | integer | Категория затрат по справочнику |
| accounting_data | object | Доп. данные для интеграции учёта |
| use_in_accounting_reports | boolean | Включать объект в отчёты БУ |

## UX рекомендации
- `budget_amount` показывает символ валюты, два знака после запятой.
- `site_area_m2` — форматировать как число с разделителем тысяч, 2 знака.
- При вводе `contract_number` проверять уникальность внутри организации.
- В карточке проекта выводить Заказчика и Бюджет в шапке. 