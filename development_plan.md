# План разработки бэкенда "Прораб-Финанс Мост"

## Базовая настройка

- [x] Установка Laravel 11
- [x] Настройка окружения (.env, база данных)
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

- [x] Создание миграций для основных сущностей:
  - [x] Проекты (projects)
  - [x] Материалы (materials)
  - [x] Единицы измерения (measurement_units)
  - [x] Виды работ (work_types)
  - [x] Поставщики (suppliers)
  - [x] Приемка материалов (material_receipts)
  - [x] Списание материалов (material_write_offs)
  - [x] Выполненные работы (completed_works)
  - [x] Остатки материалов (material_balances)
  - [x] Файлы/Изображения (files)

- [x] Создание Eloquent моделей с отношениями:
  - [x] Project
  - [x] Material
  - [x] MeasurementUnit
  - [x] WorkType
  - [x] Supplier
  - [x] MaterialReceipt
  - [x] MaterialWriteOff
  - [x] CompletedWork
  - [x] MaterialBalance
  - [x] File

## Авторизация и RBAC

- [x] Настройка JWT авторизации:
  - [x] Настройка JWT для передачи организации в токене
  - [x] Создание JwtAuthService
  - [x] Разработка middleware для проверки токена и ролей

- [x] Реализация RBAC:
  - [x] Создание базовых ролей для каждого типа пользователей:
    - [x] Лендинг/личный кабинет: organization_owner, support
    - [x] Веб-админка: admin, accountant, manager
    - [x] Мобильное приложение: foreman
  - [x] Определение разрешений для каждой роли
  - [x] Интеграция проверок разрешений в middleware

## Репозитории и сервисы

- [x] Разработка базового интерфейса репозитория с учетом мультитенантности (разделение по организациям)
- [x] Реализация базового репозитория для Eloquent
- [x] Создание репозиториев для основных сущностей (Organization, User, Project, Material, WorkType, Supplier, Role)
- [-] Создание сервисных классов:
  - [x] OrganizationService (частично реализован через репозиторий)
  - [ ] SubscriptionService
  - [x] UserService (реализованы методы для управления прорабами)
    - [x] `UserService` methods (`getForemenForCurrentOrg`, `createForeman`, etc.)
  - [x] AuthService (реализован JwtAuthService)
  - [x] ProjectService (реализованы базовые CRUD)
  - [x] MaterialService (реализованы базовые CRUD)
  - [x] WorkTypeService (реализованы базовые CRUD)
  - [x] SupplierService (реализованы базовые CRUD)
  - [ ] MaterialOperationService
  - [ ] WorkOperationService
  - [ ] ReportService (есть заготовка)
  - [ ] SyncService (есть заготовка)
  - [x] FileService (частично реализован через модель File, есть заготовка)
  - [x] LogService (реализован)
  - [x] PerformanceMonitor (реализован)

## API #1: Лендинг/Личный кабинет

- [-] Контроллеры для лендинга/личного кабинета:
  - [x] AuthController (регистрация/вход/восстановление пароля)
  - [x] OrganizationController (управление организацией - реализованы show/update)
  - [ ] SubscriptionController (управление подпиской)
  - [ ] PlanController (информация о тарифах)
  - [x] UserController (управление пользователями-**администраторами** организации - реализован CRUD с Responsable)
  - [x] SupportController (техподдержка - реализован store с Responsable)

## API #2: Веб-админка

- [-] Контроллеры для веб-админки:
  - [x] AuthController (вход)
  - [x] ProjectController (реализован CRUD)
  - [x] MaterialController (реализован CRUD)
  - [x] WorkTypeController (реализован CRUD)
  - [x] SupplierController (реализован CRUD)
  - [ ] UserManagementController (реализован CRUD для прорабов)
  - [ ] ReportController (генерация отчетов)
  - [ ] LogsController (просмотр активности)
  - [ ] SettingsController

## API #3: Мобильное приложение

- [-] Контроллеры для мобильного приложения:
  - [x] AuthController (вход)
  - [ ] ProjectController (просмотр доступных объектов)
  - [ ] MaterialReceiptController
  - [ ] MaterialWriteOffController
  - [ ] CompletedWorkController
  - [ ] SyncController (синхронизация оффлайн-данных)
  - [ ] FileUploadController (загрузка фотографий)

- [-] API Resources для каждой сущности (реализованы для админки: Project, Material, WorkType, Supplier, ForemanUser, MeasurementUnit; для ЛК: OrganizationResource, AdminUserResource)
  
## Валидация и обработка данных

- [-] Создание форм-запросов для валидации (для каждого API)
  - [x] UpdateOrganizationRequest (для API #1)
  - [ ] SubscriptionRequest
  - [x] UserRequest (реализованы RegisterRequest, LoginRequest для лендинга)
  - [x] AdminUserRequest (реализованы Store/Update для API #1)
  - [x] ForemanUserRequest (реализованы Store/Update для API #2)
  - [x] ProjectRequest (реализованы Store/Update для админки)
  - [x] MaterialRequest (реализованы Store/Update для админки)
  - [x] WorkTypeRequest (реализованы Store/Update для админки)
  - [x] SupplierRequest (реализованы Store/Update для админки)
  - [x] SupportRequest (реализован StoreSupportRequest для API #1)
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
- [x] Система логирования ошибок
- [x] Внедрение кастомных Responsable классов для API ответов

## Тестирование

- [ ] Модульные тесты:
  - [ ] Тесты сервисов
  - [ ] Тесты репозиториев

- [ ] Функциональные тесты:
  - [ ] Тесты API эндпоинтов
  - [ ] Тесты авторизации и RBAC
  - [ ] Тесты платежных интеграций

## Документация API

- [-] Настройка Swagger/OpenAPI (файл создан, описана часть API админки и API ЛК)
- [-] Документирование всех API эндпоинтов (описана часть API админки и API ЛК)
- [x] Генерация HTML документации (Redocly)
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