# Продуманная система создания пользователей для дочерних организаций

## Обзор нововведений

Новая система создания пользователей для дочерних организаций включает:

- ✅ **Автоматическое создание персональных ролей** вместо выбора из существующих
- ✅ **Шаблоны ролей** для быстрого создания стандартных позиций
- ✅ **Гибкая настройка прав доступа** для каждого пользователя
- ✅ **Массовое создание пользователей** с разными ролями
- ✅ **Визуальная настройка ролей** с цветовым кодированием
- ✅ **Автоматическая отправка приглашений** новым пользователям

## Получение шаблонов ролей

### Доступные шаблоны ролей

```http
GET /api/v1/landing/multi-organization/role-templates
```

**Ответ содержит 7 готовых шаблонов:**
- **administrator** - Администратор организации (полные права)
- **project_manager** - Менеджер проектов (управление проектами)
- **foreman** - Прораб (строительные работы)
- **accountant** - Бухгалтер (финансы и отчеты)
- **sales_manager** - Менеджер продаж (клиенты и сделки)
- **worker** - Рабочий (выполнение работ)
- **observer** - Наблюдатель (только просмотр)

## Создание пользователя с персональной ролью

### На основе шаблона роли

```http
POST /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users
```

```json
{
  "name": "Иван Петров",
  "email": "ivan.petrov@stroitel.ru",
  "password": "securePassword123",
  "auto_verify": true,
  "send_invitation": true,
  "role_data": {
    "template": "project_manager",
    "name": "Старший менеджер проектов",
    "description": "Руководитель отдела проектного управления",
    "color": "#1E40AF"
  }
}
```

### С кастомной ролью

```json
{
  "name": "Анна Смирнова",
  "email": "anna.smirnova@stroitel.ru",
  "auto_verify": true,
  "send_invitation": true,
  "role_data": {
    "name": "Специалист по снабжению",
    "description": "Закупка материалов и работа с поставщиками",
    "color": "#F59E0B",
    "permissions": [
      "materials.view", "materials.create", "materials.edit",
      "contracts.view", "contracts.create",
      "projects.view", "reports.view"
    ]
  }
}
```

## Массовое создание пользователей

```http
POST /api/v1/landing/multi-organization/child-organizations/{childOrgId}/users/bulk
```

```json
{
  "users": [
    {
      "name": "Сергей Иванов",
      "email": "sergey.ivanov@stroitel.ru",
      "role_data": {
        "template": "foreman",
        "name": "Прораб участка №1"
      }
    },
    {
      "name": "Мария Кузнецова", 
      "email": "maria.kuznetsova@stroitel.ru",
      "role_data": {
        "template": "accountant"
      }
    }
  ]
}
```

## Просмотр ролей дочерней организации

```http
GET /api/v1/landing/multi-organization/child-organizations/{childOrgId}/roles
```

Возвращает список всех созданных ролей в организации с количеством пользователей.

## Преимущества новой системы

### ✅ Для администраторов:
- **Полный контроль** над правами каждого пользователя
- **Готовые шаблоны** для быстрого создания стандартных ролей
- **Визуальная идентификация** ролей через цветовое кодирование
- **Массовые операции** для создания команд

### ✅ Для пользователей:
- **Персональные роли** точно соответствуют обязанностям
- **Понятные названия** ролей и разрешений
- **Автоматические приглашения** с инструкциями

### ✅ Для системы:
- **Масштабируемость** - каждая организация имеет свои роли
- **Безопасность** - точечное назначение прав
- **Аудит** - полная история создания и изменения ролей

## Примеры использования

### 1. Создание команды для нового проекта

```javascript
const createProjectTeam = async (childOrgId) => {
  const teamMembers = [
    {
      name: "Петр Сидоров",
      email: "petr.sidorov@stroitel.ru",
      auto_verify: true,
      send_invitation: true,
      role_data: { 
        template: "project_manager", 
        name: "Руководитель проекта 'Новостройка'" 
      }
    },
    {
      name: "Елена Волкова", 
      email: "elena.volkova@stroitel.ru",
      auto_verify: true,
      send_invitation: true,
      role_data: { 
        template: "foreman", 
        name: "Прораб стройплощадки" 
      }
    },
    {
      name: "Дмитрий Козлов",
      email: "dmitriy.kozlov@stroitel.ru",
      auto_verify: true, 
      send_invitation: true,
      role_data: { 
        template: "accountant", 
        name: "Финансист проекта" 
      }
    }
  ];

  const response = await fetch(
    `/api/v1/landing/multi-organization/child-organizations/${childOrgId}/users/bulk`,
    {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
      },
      body: JSON.stringify({ users: teamMembers })
    }
  );

  return response.json();
};
```

### 2. Создание специализированной роли

```javascript
const createSpecializedRole = async (childOrgId) => {
  const userData = {
    name: "Игорь Тестов",
    email: "igor.testov@stroitel.ru",
    auto_verify: true,
    send_invitation: true,
    role_data: {
      name: "QA Инженер",
      description: "Контроль качества строительных работ",
      color: "#8B5CF6",
      permissions: [
        "projects.view",
        "completed_work.view",
        "completed_work.edit", 
        "materials.view",
        "reports.view",
        "reports.create"
      ]
    }
  };

  const response = await fetch(
    `/api/v1/landing/multi-organization/child-organizations/${childOrgId}/users`,
    {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token 
      },
      body: JSON.stringify(userData)
    }
  );

  return response.json();
};
```

### 3. Быстрое создание отдела

```javascript
const createDepartment = async (childOrgId, departmentName) => {
  // Сначала получаем доступные шаблоны
  const templatesResponse = await fetch(
    '/api/v1/landing/multi-organization/role-templates',
    {
      headers: { 'Authorization': 'Bearer ' + token }
    }
  );
  const { data: { templates } } = await templatesResponse.json();

  // Создаем сотрудников отдела
  const departmentStaff = [
    {
      name: "Руководитель отдела",
      email: "head@department.ru",
      role_data: {
        template: "administrator",
        name: `Руководитель ${departmentName}`,
        description: `Управление отделом ${departmentName}`
      }
    },
    {
      name: "Старший специалист",
      email: "senior@department.ru", 
      role_data: {
        template: "project_manager",
        name: `Старший специалист ${departmentName}`
      }
    },
    {
      name: "Специалист",
      email: "specialist@department.ru",
      role_data: {
        template: "worker",
        name: `Специалист ${departmentName}`
      }
    }
  ];

  return await createProjectTeam(childOrgId, departmentStaff);
};
```

### 4. Создание роли с расширенными правами

```javascript
const createSupervisorRole = async (childOrgId) => {
  const supervisorData = {
    name: "Александр Надзоров",
    email: "supervisor@stroitel.ru",
    password: "SuperSecure123!",
    auto_verify: true,
    send_invitation: true,
    role_data: {
      name: "Технический надзор",
      description: "Контроль соблюдения строительных норм и технологий",
      color: "#DC2626",
      permissions: [
        // Проекты - полный доступ
        "projects.view", "projects.create", "projects.edit", "projects.delete",
        // Материалы - контроль качества
        "materials.view", "materials.edit",
        // Работы - контроль выполнения
        "work_types.view", "work_types.edit",
        "completed_work.view", "completed_work.edit",
        // Отчеты - создание актов проверки
        "reports.view", "reports.create", "reports.export",
        // Пользователи - просмотр исполнителей
        "users.view"
      ]
    }
  };

  const response = await fetch(
    `/api/v1/landing/multi-organization/child-organizations/${childOrgId}/users`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + token
      },
      body: JSON.stringify(supervisorData)
    }
  );

  return response.json();
};
```

## Валидация и ошибки

### Возможные ошибки при создании пользователя

```json
{
  "success": false,
  "error": "The given data was invalid.",
  "errors": {
    "email": ["Пользователь с таким email уже существует"],
    "role_data.name": ["Название роли обязательно"],
    "role_data.permissions": ["Должно быть выбрано хотя бы одно разрешение"]
  }
}
```

### Валидация массового создания

```json
{
  "success": true,
  "message": "Обработано пользователей: 3, успешно: 2, ошибок: 1",
  "data": {
    "total": 3,
    "successful": 2,
    "failed": 1,
    "results": [
      {
        "success": true,
        "user": { /* данные пользователя */ },
        "role": { /* данные роли */ }
      },
      {
        "success": false,
        "error": "Email уже используется",
        "user_data": {
          "name": "Дублированный пользователь",
          "email": "duplicate@email.ru"
        }
      }
    ]
  }
}
```

## Заключение

Новая система создания пользователей для дочерних организаций обеспечивает:

1. **Гибкость** - каждый пользователь получает роль под свои задачи
2. **Удобство** - готовые шаблоны для стандартных позиций
3. **Контроль** - точное управление правами доступа
4. **Эффективность** - массовые операции для быстрого развертывания команд
5. **Безопасность** - изолированные роли для каждой организации

Теперь создание пользователей для дочерних организаций стало гораздо более продуманным и функциональным! 