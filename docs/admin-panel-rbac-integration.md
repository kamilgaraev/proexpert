# 🔐 Интеграция RBAC+ABAC в Админ-панель - Инструкция для фронтендера

## 📋 Обзор системы

Админ-панель использует ту же систему авторизации RBAC+ABAC, что и ЛК. Фронтенд получает права пользователя через API и динамически управляет доступом к элементам интерфейса.

## 🛠 API Эндпоинты

### 1. Получение всех прав пользователя

**GET** `/api/admin/v1/permissions`

Возвращает полную информацию о правах текущего пользователя в админке.

#### Пример ответа:

```json
{
  "success": true,
  "data": {
    "user_id": 123,
    "organization_id": 5,
    "context": {
      "organization_id": 5
    },
    
    "roles": ["web_admin", "organization_owner"],
    "roles_detailed": [
      {
        "slug": "web_admin",
        "type": "admin",
        "is_active": true,
        "expires_at": null,
        "context_id": 5
      }
    ],
    
    "permissions": {
      "system": [
        "admin.access",
        "admin.dashboard.view",
        "admin.users.view",
        "admin.users.edit",
        "admin.projects.view",
        "admin.projects.edit"
      ],
      "modules": {
        "projects": ["projects.view", "projects.edit"],
        "materials": ["materials.view", "materials.edit"],
        "reports": ["reports.view"]
      }
    },
    
    "permissions_flat": [
      "admin.access",
      "admin.dashboard.view", 
      "admin.users.view",
      "admin.users.edit",
      "projects.view",
      "projects.edit",
      "materials.view",
      "materials.edit",
      "reports.view"
    ],
    
    "interfaces": ["admin", "lk"],
    "active_modules": ["projects", "materials", "reports"],
    
    "meta": {
      "checked_at": "2025-09-16T11:30:00.000Z",
      "total_permissions": 9,
      "total_roles": 2
    }
  }
}
```

### 2. Проверка конкретного права

**POST** `/api/admin/v1/permissions/check`

Проверяет, есть ли у пользователя конкретное право.

#### Запрос:
```json
{
  "permission": "admin.users.edit",
  "context": {
    "organization_id": 5
  },
  "interface": "admin"
}
```

#### Ответ:
```json
{
  "success": true,
  "data": {
    "has_permission": true,
    "permission": "admin.users.edit",
    "context": {
      "organization_id": 5
    },
    "user_id": 123,
    "has_interface_access": true
  }
}
```

## 💻 Интеграция на фронтенде

### 1. Сервис для работы с правами

```typescript
// services/PermissionsService.ts
export interface UserPermissions {
  user_id: number;
  organization_id: number | null;
  roles: string[];
  permissions_flat: string[];
  interfaces: string[];
  active_modules: string[];
}

export class PermissionsService {
  private permissions: UserPermissions | null = null;
  
  // Загрузить права пользователя при входе в админку
  async loadUserPermissions(): Promise<UserPermissions> {
    const response = await fetch('/api/admin/v1/permissions', {
      headers: {
        'Authorization': `Bearer ${this.getToken()}`,
        'Content-Type': 'application/json'
      }
    });
    
    if (!response.ok) {
      throw new Error('Не удалось загрузить права пользователя');
    }
    
    const data = await response.json();
    this.permissions = data.data;
    return this.permissions;
  }
  
  // Проверить наличие права
  hasPermission(permission: string): boolean {
    if (!this.permissions) {
      console.warn('Права пользователя не загружены');
      return false;
    }
    
    return this.permissions.permissions_flat.includes(permission);
  }
  
  // Проверить наличие роли
  hasRole(role: string): boolean {
    if (!this.permissions) return false;
    return this.permissions.roles.includes(role);
  }
  
  // Проверить доступ к модулю
  hasModuleAccess(module: string): boolean {
    if (!this.permissions) return false;
    return this.permissions.active_modules.includes(module);
  }
  
  // Проверить право на лету (через API)
  async checkPermission(permission: string, context?: any): Promise<boolean> {
    const response = await fetch('/api/admin/v1/permissions/check', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.getToken()}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        permission,
        context,
        interface: 'admin'
      })
    });
    
    if (!response.ok) return false;
    
    const data = await response.json();
    return data.data.has_permission;
  }
  
  private getToken(): string {
    return localStorage.getItem('admin_token') || '';
  }
}
```

### 2. Компонент для проверки прав

```tsx
// components/PermissionGuard.tsx
import React from 'react';
import { usePermissions } from '@/hooks/usePermissions';

interface PermissionGuardProps {
  permission: string;
  role?: string;
  module?: string;
  fallback?: React.ReactNode;
  children: React.ReactNode;
}

export const PermissionGuard: React.FC<PermissionGuardProps> = ({
  permission,
  role,
  module,
  fallback = null,
  children
}) => {
  const { hasPermission, hasRole, hasModuleAccess } = usePermissions();
  
  // Проверяем право
  if (permission && !hasPermission(permission)) {
    return <>{fallback}</>;
  }
  
  // Проверяем роль (если указана)
  if (role && !hasRole(role)) {
    return <>{fallback}</>;
  }
  
  // Проверяем модуль (если указан)
  if (module && !hasModuleAccess(module)) {
    return <>{fallback}</>;
  }
  
  return <>{children}</>;
};
```

### 3. Хук для удобного использования

```typescript
// hooks/usePermissions.ts
import { useContext } from 'react';
import { PermissionsContext } from '@/contexts/PermissionsContext';

export const usePermissions = () => {
  const context = useContext(PermissionsContext);
  
  if (!context) {
    throw new Error('usePermissions must be used within PermissionsProvider');
  }
  
  return context;
};
```

### 4. Контекст для прав

```tsx
// contexts/PermissionsContext.tsx
import React, { createContext, useEffect, useState } from 'react';
import { PermissionsService, UserPermissions } from '@/services/PermissionsService';

interface PermissionsContextType {
  permissions: UserPermissions | null;
  hasPermission: (permission: string) => boolean;
  hasRole: (role: string) => boolean;
  hasModuleAccess: (module: string) => boolean;
  loading: boolean;
  reload: () => Promise<void>;
}

export const PermissionsContext = createContext<PermissionsContextType | null>(null);

export const PermissionsProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [permissions, setPermissions] = useState<UserPermissions | null>(null);
  const [loading, setLoading] = useState(true);
  const permissionsService = new PermissionsService();
  
  const loadPermissions = async () => {
    try {
      setLoading(true);
      const userPermissions = await permissionsService.loadUserPermissions();
      setPermissions(userPermissions);
    } catch (error) {
      console.error('Ошибка загрузки прав:', error);
    } finally {
      setLoading(false);
    }
  };
  
  useEffect(() => {
    loadPermissions();
  }, []);
  
  const hasPermission = (permission: string): boolean => {
    return permissionsService.hasPermission(permission);
  };
  
  const hasRole = (role: string): boolean => {
    return permissionsService.hasRole(role);
  };
  
  const hasModuleAccess = (module: string): boolean => {
    return permissionsService.hasModuleAccess(module);
  };
  
  return (
    <PermissionsContext.Provider 
      value={{
        permissions,
        hasPermission,
        hasRole,
        hasModuleAccess,
        loading,
        reload: loadPermissions
      }}
    >
      {children}
    </PermissionsContext.Provider>
  );
};
```

## 🎯 Примеры использования

### 1. Скрытие элементов интерфейса

```tsx
// Скрыть кнопку создания пользователя
<PermissionGuard permission="admin.users.create">
  <Button onClick={createUser}>Создать пользователя</Button>
</PermissionGuard>

// Скрыть весь раздел материалов
<PermissionGuard module="materials">
  <MaterialsSection />
</PermissionGuard>

// Показать заглушку, если нет прав
<PermissionGuard 
  permission="admin.reports.view"
  fallback={<div>У вас нет доступа к отчетам</div>}
>
  <ReportsPage />
</PermissionGuard>
```

### 2. Условное отображение в компонентах

```tsx
// components/UserTable.tsx
export const UserTable: React.FC = () => {
  const { hasPermission } = usePermissions();
  
  return (
    <Table>
      <TableHead>
        <tr>
          <th>Имя</th>
          <th>Email</th>
          {hasPermission('admin.users.edit') && <th>Действия</th>}
        </tr>
      </TableHead>
      <TableBody>
        {users.map(user => (
          <tr key={user.id}>
            <td>{user.name}</td>
            <td>{user.email}</td>
            {hasPermission('admin.users.edit') && (
              <td>
                <Button onClick={() => editUser(user)}>Редактировать</Button>
                {hasPermission('admin.users.block') && (
                  <Button onClick={() => blockUser(user)}>Заблокировать</Button>
                )}
              </td>
            )}
          </tr>
        ))}
      </TableBody>
    </Table>
  );
};
```

### 3. Защита маршрутов

```tsx
// router/AdminRoutes.tsx
export const AdminRoutes = () => {
  return (
    <Routes>
      <Route path="/dashboard" element={
        <PermissionGuard permission="admin.dashboard.view">
          <Dashboard />
        </PermissionGuard>
      } />
      
      <Route path="/users" element={
        <PermissionGuard permission="admin.users.view">
          <UsersPage />
        </PermissionGuard>
      } />
      
      <Route path="/projects" element={
        <PermissionGuard permission="admin.projects.view">
          <ProjectsPage />
        </PermissionGuard>
      } />
      
      <Route path="/analytics/*" element={
        <PermissionGuard permission="admin.projects.analytics">
          <AnalyticsRoutes />
        </PermissionGuard>
      } />
    </Routes>
  );
};
```

### 4. Динамическое меню

```tsx
// components/AdminSidebar.tsx
export const AdminSidebar: React.FC = () => {
  const { hasPermission, hasModuleAccess } = usePermissions();
  
  const menuItems = [
    {
      label: 'Дашборд',
      path: '/dashboard',
      permission: 'admin.dashboard.view',
      icon: DashboardIcon
    },
    {
      label: 'Пользователи',
      path: '/users',
      permission: 'admin.users.view',
      icon: UsersIcon
    },
    {
      label: 'Проекты',
      path: '/projects',
      permission: 'admin.projects.view',
      module: 'projects',
      icon: ProjectsIcon
    },
    {
      label: 'Материалы',
      path: '/materials',
      permission: 'admin.materials.view',
      module: 'materials',
      icon: MaterialsIcon
    },
    {
      label: 'Отчеты',
      path: '/reports',
      permission: 'admin.reports.view',
      module: 'reports',
      icon: ReportsIcon
    }
  ];
  
  const visibleItems = menuItems.filter(item => {
    if (item.permission && !hasPermission(item.permission)) {
      return false;
    }
    if (item.module && !hasModuleAccess(item.module)) {
      return false;
    }
    return true;
  });
  
  return (
    <nav>
      {visibleItems.map(item => (
        <NavLink key={item.path} to={item.path}>
          <item.icon />
          {item.label}
        </NavLink>
      ))}
    </nav>
  );
};
```

## 📋 Основные права для админки

### Системные права:
- `admin.access` - доступ к админке
- `admin.dashboard.view` - просмотр дашборда
- `admin.users.view` - просмотр пользователей
- `admin.users.edit` - редактирование пользователей
- `admin.users.create` - создание пользователей
- `admin.users.block` - блокировка пользователей
- `admin.projects.view` - просмотр проектов
- `admin.projects.edit` - редактирование проектов
- `admin.projects.analytics` - аналитика проектов
- `admin.materials.view` - просмотр материалов
- `admin.materials.edit` - редактирование материалов
- `admin.materials.import` - импорт материалов
- `admin.contracts.view` - просмотр контрактов
- `admin.contracts.edit` - редактирование контрактов
- `admin.reports.view` - просмотр отчетов
- `admin.reports.export` - экспорт отчетов
- `admin.catalogs.manage` - управление справочниками

### Модульные права:
- `projects.*` - все права по проектам
- `materials.*` - все права по материалам
- `reports.*` - все права по отчетам
- `contracts.*` - все права по контрактам

## 🚀 Инициализация

```tsx
// App.tsx
export const App: React.FC = () => {
  return (
    <PermissionsProvider>
      <AdminRoutes />
    </PermissionsProvider>
  );
};
```

## ⚡ Лучшие практики

1. **Загружайте права при входе** и кешируйте их в состоянии
2. **Используйте PermissionGuard** для защиты элементов UI
3. **Проверяйте права на уровне роутов** для безопасности
4. **Обновляйте права** при смене роли/организации
5. **Показывайте заглушки** вместо скрытия элементов, где это уместно
6. **Логируйте ошибки** доступа для мониторинга
7. **Кешируйте проверки** дорогих операций

Эта система обеспечивает гибкое и безопасное управление доступом в админ-панели на основе ролей и прав пользователя!
