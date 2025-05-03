# План разработки бэкенда "Прораб-Финанс Мост"

## Базовая настройка

- [x] Установка Laravel 11
- [ ] Настройка окружения (.env, база данных)
- [x] Установка и настройка пакета JWT (tymon/jwt-auth)
- [x] Настройка маршрутизации для трех отдельных API

## Модели организаций и пользователей

- [x] Создание миграций для системы пользователей:
  - [x] Организации (organizations)
  - [x] Роли (roles)
  - [x] Разрешения (permissions)
  - [x] Связь пользователь-роль (role_user)
  - [x] Связь роль-разрешение (permission_role)
  - [x] Связь пользователь-организация (organization_user)
  - [x] Модификация стандартной таблицы users

- [x] Создание Eloquent моделей с отношениями:
  - [x] User
  - [x] Organization
  - [x] Role
  - [x] Permission

## Миграции и модели данных

- [ ] Создание миграций для основных сущностей:
  - [ ] Проекты (projects)
  - [ ] Материалы (materials)
  - [ ] Единицы измерения (measurement_units)
  - [ ] Виды работ (work_types)
  - [ ] Поставщики (suppliers)
  - [ ] Приемка материалов (material_receipts)
  - [ ] Списание материалов (material_write_offs)
  - [ ] Выполненные работы (completed_works)
  - [ ] Остатки материалов (material_balances)
  - [ ] Файлы/Изображения (files)

- [ ] Создание Eloquent моделей с отношениями:
  - [ ] Project
  - [ ] Material
  - [ ] MeasurementUnit
  - [ ] WorkType
  - [ ] Supplier
  - [ ] MaterialReceipt
  - [ ] MaterialWriteOff
  - [ ] CompletedWork
  - [ ] MaterialBalance
  - [ ] File

## Авторизация и RBAC

- [ ] Настройка JWT авторизации:
  - [ ] Настройка JWT для передачи организации в токене
  - [ ] Создание JwtAuthService
  - [ ] Разработка middleware для проверки токена и ролей

- [ ] Реализация RBAC:
  - [ ] Создание базовых ролей для каждого типа пользователей:
    - [ ] Лендинг/личный кабинет: organization_owner, support
    - [ ] Веб-админка: admin, accountant, manager
    - [ ] Мобильное приложение: foreman
  - [ ] Определение разрешений для каждой роли
  - [ ] Интеграция проверок разрешений в middleware

## Репозитории и сервисы

- [ ] Разработка базового интерфейса репозитория с учетом мультитенантности (разделение по организациям)
- [ ] Реализация базового репозитория для Eloquent
- [ ] Создание сервисных классов:
  - [ ] OrganizationService
  - [ ] SubscriptionService
  - [ ] UserService
  - [ ] AuthService
  - [ ] ProjectService
  - [ ] MaterialService
  - [ ] WorkTypeService
  - [ ] SupplierService
  - [ ] MaterialOperationService
  - [ ] WorkOperationService
  - [ ] ReportService
  - [ ] SyncService
  - [ ] FileService

## API #1: Лендинг/Личный кабинет

- [ ] Контроллеры для лендинга/личного кабинета:
  - [ ] AuthController (регистрация/вход/восстановление пароля)
  - [ ] OrganizationController (управление организацией)
  - [ ] SubscriptionController (управление подпиской)
  - [ ] PlanController (информация о тарифах)
  - [ ] UserController (управление пользователями организации)
  - [ ] SupportController (техподдержка)

## API #2: Веб-админка

- [ ] Контроллеры для веб-админки:
  - [ ] AuthController (вход)
  - [ ] ProjectController
  - [ ] MaterialController
  - [ ] WorkTypeController
  - [ ] SupplierController
  - [ ] UserManagementController (управление прорабами)
  - [ ] ReportController (генерация отчетов)
  - [ ] LogsController (просмотр активности)
  - [ ] SettingsController

## API #3: Мобильное приложение

- [ ] Контроллеры для мобильного приложения:
  - [ ] AuthController (вход)
  - [ ] ProjectController (просмотр доступных объектов)
  - [ ] MaterialReceiptController
  - [ ] MaterialWriteOffController
  - [ ] CompletedWorkController
  - [ ] SyncController (синхронизация оффлайн-данных)
  - [ ] FileUploadController (загрузка фотографий)

- [ ] API Resources для каждой сущности
  
## Валидация и обработка данных

- [ ] Создание форм-запросов для валидации (для каждого API)
  - [ ] OrganizationRequest
  - [ ] SubscriptionRequest
  - [ ] UserRequest
  - [ ] ProjectRequest
  - [ ] MaterialRequest
  - [ ] WorkTypeRequest
  - [ ] SupplierRequest
  - [ ] MaterialReceiptRequest
  - [ ] MaterialWriteOffRequest
  - [ ] CompletedWorkRequest
  - [ ] ReportRequest
  - [ ] FileUploadRequest

## Обработка платежей

- [ ] Интеграция с платежной системой
- [ ] Обработка webhooks от платежной системы
- [ ] Управление подписками

## Очереди и фоновые задачи

- [ ] Настройка очередей (Redis)
- [ ] Создание задач:
  - [ ] SyncOfflineDataJob
  - [ ] GenerateReportJob
  - [ ] ProcessPaymentJob
  - [ ] SendNotificationJob

## Обработка ошибок и исключений

- [ ] Создание кастомных исключений
- [ ] Настройка обработчика исключений для каждого API
- [ ] Система логирования ошибок

## Тестирование

- [ ] Модульные тесты:
  - [ ] Тесты сервисов
  - [ ] Тесты репозиториев

- [ ] Функциональные тесты:
  - [ ] Тесты API эндпоинтов
  - [ ] Тесты авторизации и RBAC
  - [ ] Тесты платежных интеграций

## Документация API

- [ ] Настройка Swagger/OpenAPI
- [ ] Документирование всех API эндпоинтов
- [ ] Инструкции по интеграции для фронтенд-разработчиков

## Оптимизация и безопасность

- [ ] Настройка кэширования
- [ ] Настройка Rate Limiting
- [ ] Проверка безопасности всех API
- [ ] Защита от CSRF, XSS и других уязвимостей

## Развертывание

- [ ] Настройка CI/CD
- [ ] Настройка dev/staging/production окружений
- [ ] Подготовка к развертыванию 