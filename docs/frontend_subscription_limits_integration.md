# Интеграция API Лимитов Подписки - Руководство для Фронтенда

## Обзор

API лимитов подписки предоставляет информацию о текущих лимитах пользователя, использовании ресурсов и статусе подписки. Этот API позволяет отображать в интерфейсе актуальную информацию о лимитах и предупреждения.

## Базовая интеграция

### Эндпоинт
```
GET /api/v1/landing/billing/subscription/limits
```

### Заголовки
```javascript
{
  'Authorization': `Bearer ${accessToken}`,
  'Accept': 'application/json',
  'Content-Type': 'application/json'
}
```

### Пример запроса (JavaScript/Fetch)
```javascript
async function getSubscriptionLimits() {
  try {
    const response = await fetch('/api/v1/landing/billing/subscription/limits', {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${localStorage.getItem('access_token')}`,
        'Accept': 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const data = await response.json();
    return data.data;
  } catch (error) {
    console.error('Ошибка получения лимитов подписки:', error);
    throw error;
  }
}
```

### Пример запроса (Axios)
```javascript
import axios from 'axios';

const apiClient = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Accept': 'application/json'
  }
});

// Добавление токена к каждому запросу
apiClient.interceptors.request.use(config => {
  const token = localStorage.getItem('access_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

async function getSubscriptionLimits() {
  try {
    const response = await apiClient.get('/landing/billing/subscription/limits');
    return response.data.data;
  } catch (error) {
    console.error('Ошибка получения лимитов подписки:', error);
    throw error;
  }
}
```

## Структура ответа

### Пользователь с активной подпиской
```javascript
{
  has_subscription: true,
  subscription: {
    id: 123,
    status: "active",
    plan_name: "Профессиональный",
    plan_description: "Расширенные возможности для строительных компаний",
    is_trial: false,
    trial_ends_at: null,
    ends_at: "2024-12-31 23:59:59",
    next_billing_at: "2024-12-01 00:00:00",
    is_canceled: false
  },
  limits: {
    foremen: {
      limit: 10,
      used: 7,
      remaining: 3,
      percentage_used: 70.0,
      is_unlimited: false,
      status: "approaching"
    },
    projects: {
      limit: 50,
      used: 23,
      remaining: 27,
      percentage_used: 46.0,
      is_unlimited: false,
      status: "normal"
    },
    storage: {
      limit_gb: 100.0,
      used_gb: 45.67,
      remaining_gb: 54.33,
      percentage_used: 45.7,
      is_unlimited: false,
      status: "normal"
    }
  },
  features: [
    "Неограниченное количество контрактов",
    "Расширенная отчетность",
    "Приоритетная поддержка"
  ],
  warnings: [
    {
      type: "foremen",
      level: "warning",
      message: "Приближаетесь к лимиту количества прорабов"
    }
  ],
  upgrade_required: false
}
```

### Пользователь без подписки
```javascript
{
  has_subscription: false,
  subscription: null,
  limits: {
    foremen: {
      limit: 1,
      used: 1,
      remaining: 0,
      percentage_used: 100.0,
      is_unlimited: false,
      status: "exceeded"
    },
    projects: {
      limit: 1,
      used: 0,
      remaining: 1,
      percentage_used: 0.0,
      is_unlimited: false,
      status: "normal"
    },
    storage: {
      limit_gb: 0.1,
      used_gb: 0.02,
      remaining_gb: 0.08,
      percentage_used: 20.0,
      is_unlimited: false,
      status: "normal"
    }
  },
  features: [],
  warnings: [
    {
      type: "foremen",
      level: "critical",
      message: "Достигнут лимит бесплатного тарифа. Оформите подписку для добавления прорабов."
    }
  ],
  upgrade_required: true
}
```

## Статусы лимитов

| Статус | Описание | Цвет UI | Действие |
|--------|----------|---------|----------|
| `normal` | < 60% использования | Зеленый | Нет |
| `approaching` | 60-79% использования | Желтый | Информирование |
| `warning` | 80-99% использования | Оранжевый | Предупреждение |
| `exceeded` | ≥ 100% использования | Красный | Блокировка действий |
| `unlimited` | Безлимитный ресурс | Синий | Нет |

## Компоненты интерфейса

### 1. Виджет лимитов (React)

```jsx
import React from 'react';

const LimitWidget = ({ title, limit, unit, isStorage = false }) => {
  const getStatusColor = (status) => {
    const colors = {
      normal: 'bg-green-500',
      approaching: 'bg-yellow-500',
      warning: 'bg-orange-500',
      exceeded: 'bg-red-500',
      unlimited: 'bg-blue-500'
    };
    return colors[status] || 'bg-gray-500';
  };

  const formatValue = (value) => {
    return isStorage ? value.toFixed(2) : value.toString();
  };

  if (limit.is_unlimited) {
    return (
      <div className="border rounded-lg p-4">
        <h4 className="font-medium mb-2">{title}</h4>
        <div className="flex items-center text-blue-600">
          <span className="text-sm">Безлимитно</span>
        </div>
      </div>
    );
  }

  return (
    <div className="border rounded-lg p-4">
      <div className="flex justify-between items-center mb-2">
        <h4 className="font-medium">{title}</h4>
        <span className="text-sm text-gray-600">
          {formatValue(isStorage ? limit.used_gb : limit.used)} / 
          {formatValue(isStorage ? limit.limit_gb : limit.limit)} {unit}
        </span>
      </div>
      
      <div className="w-full bg-gray-200 rounded-full h-2 mb-2">
        <div
          className={`h-2 rounded-full transition-all duration-300 ${getStatusColor(limit.status)}`}
          style={{ width: `${Math.min(limit.percentage_used, 100)}%` }}
        />
      </div>
      
      <div className="flex justify-between text-sm text-gray-600">
        <span>Использовано: {limit.percentage_used}%</span>
        {limit.remaining > 0 && (
          <span>
            Осталось: {formatValue(isStorage ? limit.remaining_gb : limit.remaining)} {unit}
          </span>
        )}
      </div>
    </div>
  );
};

export default LimitWidget;
```

### 2. Панель предупреждений (Vue.js)

```vue
<template>
  <div v-if="warnings.length > 0" class="warnings-panel">
    <h4 class="warnings-title">Предупреждения</h4>
    <div class="warnings-list">
      <div 
        v-for="warning in warnings" 
        :key="warning.type"
        :class="getWarningClass(warning.level)"
        class="warning-item"
      >
        <div class="warning-icon">
          <i :class="getWarningIcon(warning.level)"></i>
        </div>
        <div class="warning-message">
          {{ warning.message }}
        </div>
        <button 
          v-if="warning.level === 'critical'"
          @click="$emit('upgrade-click')"
          class="warning-action"
        >
          Обновить тариф
        </button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'WarningsPanel',
  props: {
    warnings: {
      type: Array,
      required: true
    }
  },
  emits: ['upgrade-click'],
  methods: {
    getWarningClass(level) {
      return {
        'warning-critical': level === 'critical',
        'warning-normal': level === 'warning'
      };
    },
    getWarningIcon(level) {
      return level === 'critical' 
        ? 'fas fa-exclamation-triangle' 
        : 'fas fa-info-circle';
    }
  }
}
</script>

<style scoped>
.warnings-panel {
  margin-top: 1.5rem;
}

.warnings-title {
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.5rem;
}

.warning-item {
  display: flex;
  align-items: center;
  padding: 0.75rem;
  border-radius: 0.5rem;
  margin-bottom: 0.5rem;
}

.warning-critical {
  background-color: #fef2f2;
  border: 1px solid #fecaca;
  color: #991b1b;
}

.warning-normal {
  background-color: #fffbeb;
  border: 1px solid #fed7aa;
  color: #92400e;
}

.warning-icon {
  margin-right: 0.75rem;
  font-size: 1.125rem;
}

.warning-message {
  flex: 1;
}

.warning-action {
  background-color: #3b82f6;
  color: white;
  padding: 0.375rem 0.75rem;
  border: none;
  border-radius: 0.25rem;
  cursor: pointer;
  font-size: 0.875rem;
}

.warning-action:hover {
  background-color: #2563eb;
}
</style>
```

### 3. Хук для управления состоянием (React)

```javascript
import { useState, useEffect, useCallback } from 'react';

export const useSubscriptionLimits = (options = {}) => {
  const {
    autoRefresh = false,
    refreshInterval = 300000, // 5 минут
    onWarning = null,
    onCritical = null
  } = options;

  const [state, setState] = useState({
    data: null,
    loading: true,
    error: null,
    lastUpdated: null
  });

  const fetchLimits = useCallback(async () => {
    try {
      setState(prev => ({ ...prev, error: null }));
      
      const response = await fetch('/api/v1/landing/billing/subscription/limits', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('access_token')}`,
          'Accept': 'application/json'
        }
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const result = await response.json();
      const data = result.data;

      // Вызываем колбэки для предупреждений
      if (data.warnings?.length > 0) {
        const criticalWarnings = data.warnings.filter(w => w.level === 'critical');
        const normalWarnings = data.warnings.filter(w => w.level === 'warning');

        if (criticalWarnings.length > 0 && onCritical) {
          onCritical(criticalWarnings);
        }
        if (normalWarnings.length > 0 && onWarning) {
          onWarning(normalWarnings);
        }
      }

      setState({
        data,
        loading: false,
        error: null,
        lastUpdated: new Date()
      });

    } catch (error) {
      setState(prev => ({
        ...prev,
        loading: false,
        error: error.message
      }));
    }
  }, [onWarning, onCritical]);

  // Первоначальная загрузка
  useEffect(() => {
    fetchLimits();
  }, [fetchLimits]);

  // Автообновление
  useEffect(() => {
    if (autoRefresh && refreshInterval > 0) {
      const interval = setInterval(fetchLimits, refreshInterval);
      return () => clearInterval(interval);
    }
  }, [autoRefresh, refreshInterval, fetchLimits]);

  const refresh = useCallback(() => {
    setState(prev => ({ ...prev, loading: true }));
    fetchLimits();
  }, [fetchLimits]);

  // Вспомогательные геттеры
  const hasSubscription = state.data?.has_subscription || false;
  const needsUpgrade = state.data?.upgrade_required || false;
  const hasWarnings = state.data?.warnings?.length > 0 || false;
  const criticalWarnings = state.data?.warnings?.filter(w => w.level === 'critical') || [];

  return {
    ...state,
    refresh,
    hasSubscription,
    needsUpgrade,
    hasWarnings,
    criticalWarnings
  };
};
```

## Интеграция с уведомлениями

### Система уведомлений

```javascript
// Утилита для отображения уведомлений
export const showLimitNotification = (warning) => {
  const config = {
    type: warning.level === 'critical' ? 'error' : 'warning',
    title: 'Внимание к лимитам',
    message: warning.message,
    duration: warning.level === 'critical' ? 0 : 5000, // Критические не исчезают
    actions: warning.level === 'critical' ? [
      {
        text: 'Обновить тариф',
        action: () => window.location.href = '/billing/plans'
      }
    ] : []
  };

  // Интеграция с вашей системой уведомлений
  NotificationService.show(config);
};

// Использование в компоненте
const { criticalWarnings } = useSubscriptionLimits({
  onCritical: (warnings) => {
    warnings.forEach(showLimitNotification);
  }
});
```

## Кэширование и оптимизация

### Service Worker для кэширования

```javascript
// sw.js
const CACHE_NAME = 'subscription-limits-v1';
const LIMITS_URL = '/api/v1/landing/billing/subscription/limits';

self.addEventListener('fetch', event => {
  if (event.request.url.includes(LIMITS_URL)) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache => {
        return fetch(event.request)
          .then(response => {
            // Кэшируем успешный ответ на 5 минут
            if (response.ok) {
              const responseClone = response.clone();
              cache.put(event.request, responseClone);
            }
            return response;
          })
          .catch(() => {
            // Возвращаем кэшированные данные при отсутствии сети
            return cache.match(event.request);
          });
      })
    );
  }
});
```

### React Query интеграция

```javascript
import { useQuery } from '@tanstack/react-query';

export const useSubscriptionLimitsQuery = () => {
  return useQuery({
    queryKey: ['subscription-limits'],
    queryFn: async () => {
      const response = await fetch('/api/v1/landing/billing/subscription/limits', {
        headers: {
          'Authorization': `Bearer ${localStorage.getItem('access_token')}`,
          'Accept': 'application/json'
        }
      });
      
      if (!response.ok) {
        throw new Error('Ошибка загрузки лимитов');
      }
      
      const data = await response.json();
      return data.data;
    },
    staleTime: 5 * 60 * 1000, // 5 минут
    cacheTime: 10 * 60 * 1000, // 10 минут
    refetchOnWindowFocus: true,
    retry: 3
  });
};
```

## Мобильная адаптация

### Адаптивные стили

```css
/* Десктоп */
.limits-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.limit-widget {
  padding: 1rem;
  border-radius: 0.5rem;
  border: 1px solid #e5e7eb;
}

/* Планшет */
@media (max-width: 1024px) {
  .limits-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

/* Мобильный */
@media (max-width: 768px) {
  .limits-grid {
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }
  
  .limit-widget {
    padding: 0.75rem;
  }
  
  .warning-item {
    flex-direction: column;
    align-items: stretch;
    gap: 0.5rem;
  }
  
  .warning-action {
    align-self: flex-start;
  }
}

/* Маленькие экраны */
@media (max-width: 480px) {
  .limits-grid {
    gap: 0.5rem;
  }
  
  .limit-widget {
    padding: 0.5rem;
  }
  
  .limit-widget h4 {
    font-size: 0.875rem;
  }
}
```

## Тестирование

### Модульные тесты (Jest)

```javascript
import { renderHook, waitFor } from '@testing-library/react';
import { rest } from 'msw';
import { setupServer } from 'msw/node';
import { useSubscriptionLimits } from '../hooks/useSubscriptionLimits';

const server = setupServer(
  rest.get('/api/v1/landing/billing/subscription/limits', (req, res, ctx) => {
    return res(ctx.json({
      success: true,
      data: {
        has_subscription: true,
        limits: {
          foremen: { limit: 10, used: 8, status: 'warning' }
        },
        warnings: [
          { type: 'foremen', level: 'warning', message: 'Предупреждение' }
        ]
      }
    }));
  })
);

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('useSubscriptionLimits', () => {
  test('загружает лимиты подписки', async () => {
    const { result } = renderHook(() => useSubscriptionLimits());

    expect(result.current.loading).toBe(true);

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.data).toBeDefined();
    expect(result.current.hasSubscription).toBe(true);
    expect(result.current.hasWarnings).toBe(true);
  });

  test('вызывает колбэк при предупреждениях', async () => {
    const onWarning = jest.fn();
    
    renderHook(() => useSubscriptionLimits({ onWarning }));

    await waitFor(() => {
      expect(onWarning).toHaveBeenCalledWith([
        expect.objectContaining({ level: 'warning' })
      ]);
    });
  });
});
```

### E2E тесты (Cypress)

```javascript
describe('Лимиты подписки', () => {
  beforeEach(() => {
    cy.login(); // Кастомная команда для входа
    cy.intercept('GET', '/api/v1/landing/billing/subscription/limits', {
      fixture: 'subscription-limits.json'
    }).as('getLimits');
  });

  it('отображает лимиты с предупреждениями', () => {
    cy.visit('/billing');
    cy.wait('@getLimits');

    cy.get('[data-testid="limits-panel"]').should('be.visible');
    cy.get('[data-testid="limit-foremen"]').should('contain', '8 / 10');
    cy.get('[data-testid="warning-item"]').should('be.visible');
  });

  it('показывает кнопку обновления для критических предупреждений', () => {
    cy.intercept('GET', '/api/v1/landing/billing/subscription/limits', {
      fixture: 'subscription-limits-critical.json'
    }).as('getCriticalLimits');

    cy.visit('/billing');
    cy.wait('@getCriticalLimits');

    cy.get('[data-testid="upgrade-button"]').should('be.visible');
    cy.get('[data-testid="upgrade-button"]').click();
    cy.url().should('include', '/billing/plans');
  });
});
```

## Безопасность

### Защита от XSS

```javascript
// Санитизация данных от API
import DOMPurify from 'dompurify';

const sanitizeApiData = (data) => {
  if (data.subscription?.plan_description) {
    data.subscription.plan_description = DOMPurify.sanitize(
      data.subscription.plan_description
    );
  }
  
  if (data.warnings) {
    data.warnings = data.warnings.map(warning => ({
      ...warning,
      message: DOMPurify.sanitize(warning.message)
    }));
  }
  
  return data;
};
```

### Валидация данных

```javascript
import Joi from 'joi';

const limitsSchema = Joi.object({
  has_subscription: Joi.boolean().required(),
  subscription: Joi.object().allow(null),
  limits: Joi.object({
    foremen: Joi.object({
      limit: Joi.number().allow(null),
      used: Joi.number().required(),
      percentage_used: Joi.number().min(0).max(100).required(),
      status: Joi.string().valid('normal', 'approaching', 'warning', 'exceeded', 'unlimited').required()
    }).required(),
    projects: Joi.object().required(),
    storage: Joi.object().required()
  }).required(),
  warnings: Joi.array().items(
    Joi.object({
      type: Joi.string().required(),
      level: Joi.string().valid('warning', 'critical').required(),
      message: Joi.string().required()
    })
  ).required()
});

export const validateLimitsData = (data) => {
  const { error, value } = limitsSchema.validate(data);
  if (error) {
    throw new Error(`Некорректные данные лимитов: ${error.message}`);
  }
  return value;
};
```

Эта документация предоставляет полное руководство по интеграции API лимитов подписки во фронтенд-приложение с учетом современных практик разработки, безопасности и пользовательского опыта. 