# Реализация Trial периода для модулей

## Текущее состояние

### ✅ Что уже есть (инфраструктура):

1. **БД готова:**
   ```sql
   -- Таблица: organization_module_activations
   status ENUM('active', 'suspended', 'expired', 'trial', 'pending')
   trial_ends_at TIMESTAMP NULL
   ```

2. **Модель готова:**
   ```php
   // OrganizationModuleActivation
   public function isTrial(): bool
   public function getDaysUntilTrialEnd(): ?int
   ```

3. **JSON конфиг модуля поддерживает:**
   ```json
   {
     "pricing": {
       "trial_days": 14
     }
   }
   ```

### ❌ Чего НЕТ (логика):

1. **Метод активации trial** - `activateTrial()`
2. **Проверка использования trial** - `hasUsedTrial()`
3. **Автоматический переход** trial → paid
4. **Уведомления** о скором окончании trial
5. **UI для активации trial**

---

## Что нужно реализовать

### Phase 0: Trial Logic (1 неделя)

#### 1. Добавить метод `activateTrial()` в ModuleManager

```php
<?php
// app/Modules/Core/ModuleManager.php

public function activateTrial(int $organizationId, string $moduleSlug): array
{
    $module = Module::where('slug', $moduleSlug)->firstOrFail();
    $organization = Organization::findOrFail($organizationId);
    
    // Проверка: был ли уже trial
    if ($this->hasUsedTrial($organizationId, $module->id)) {
        throw new \Exception('Trial период уже был использован для этого модуля');
    }
    
    // Получаем trial_days из конфига модуля
    $pricingConfig = $module->pricing_config ?? [];
    $trialDays = $pricingConfig['trial_days'] ?? 14;
    
    return DB::transaction(function () use ($organizationId, $module, $trialDays) {
        $activation = OrganizationModuleActivation::create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'trial',
            'activated_at' => now(),
            'trial_ends_at' => now()->addDays($trialDays),
            'expires_at' => now()->addDays($trialDays), // Временно активен
            'paid_amount' => 0,
            'module_settings' => [],
            'usage_stats' => []
        ]);
        
        // Очищаем кэш доступа
        $this->accessController->clearAccessCache($organizationId);
        
        // Событие активации trial
        event(new TrialActivated($organizationId, $module->slug, $trialDays));
        
        return [
            'success' => true,
            'activation' => $activation,
            'trial_ends_at' => $activation->trial_ends_at,
            'days_left' => $trialDays
        ];
    });
}
```

#### 2. Проверка использования trial

```php
public function hasUsedTrial(int $organizationId, int $moduleId): bool
{
    return OrganizationModuleActivation::where('organization_id', $organizationId)
        ->where('module_id', $moduleId)
        ->whereNotNull('trial_ends_at') // Был хотя бы один trial
        ->exists();
}
```

#### 3. Обновить логику `activate()` для поддержки trial

```php
public function activate(int $organizationId, string $moduleSlug, array $options = []): array
{
    $module = Module::where('slug', $moduleSlug)->firstOrFail();
    $organization = Organization::findOrFail($organizationId);
    
    // НОВОЕ: Если указано activateTrial=true и trial не использован
    if (($options['activate_trial'] ?? false) && !$this->hasUsedTrial($organizationId, $module->id)) {
        return $this->activateTrial($organizationId, $moduleSlug);
    }
    
    // Существующая логика платной активации
    try {
        DB::transaction(function () use ($organizationId, $module, $organization, $options) {
            // Списываем деньги
            if (!$module->isFree() && !$this->billingEngine->chargeForModule($organization, $module)) {
                throw new \Exception('Ошибка списания средств');
            }
            
            $pricingConfig = $module->pricing_config ?? [];
            $durationDays = $pricingConfig['duration_days'] ?? 30;
            
            OrganizationModuleActivation::updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'module_id' => $module->id,
                ],
                [
                    'activated_at' => now(),
                    'expires_at' => $module->isFree() 
                        ? null 
                        : ($module->billing_model === 'subscription' 
                            ? now()->addDays($durationDays) 
                            : null),
                    'status' => 'active',
                    'paid_amount' => $module->isFree() 
                        ? 0 
                        : $this->billingEngine->calculateChargeAmount($module),
                    'module_settings' => $options['settings'] ?? [],
                    'usage_stats' => []
                ]
            );
            
            $this->accessController->clearAccessCache($organizationId);
        });
        
        event(new ModuleActivated($organizationId, $moduleSlug));
        
        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

#### 4. Command для проверки истекших trial

```php
<?php
// app/Console/Commands/ConvertExpiredTrials.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrganizationModuleActivation;

class ConvertExpiredTrials extends Command
{
    protected $signature = 'modules:convert-expired-trials';
    protected $description = 'Проверяет истекшие trial периоды и деактивирует модули';

    public function handle()
    {
        $expiredTrials = OrganizationModuleActivation::where('status', 'trial')
            ->where('trial_ends_at', '<', now())
            ->get();
        
        $this->info("Найдено {$expiredTrials->count()} истекших trial периодов");
        
        foreach ($expiredTrials as $activation) {
            // Меняем статус на expired
            $activation->update([
                'status' => 'expired',
                'expires_at' => now()
            ]);
            
            // Очищаем кэш доступа
            app(\App\Modules\Core\AccessController::class)->clearAccessCache($activation->organization_id);
            
            // Отправляем уведомление
            $activation->organization->notify(new TrialExpiredNotification($activation));
            
            $this->info("Trial истек для организации {$activation->organization_id}, модуль {$activation->module->slug}");
        }
        
        return Command::SUCCESS;
    }
}
```

#### 5. Scheduled task в Kernel

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    // Проверяем истекшие trial каждый час
    $schedule->command('modules:convert-expired-trials')->hourly();
    
    // Отправляем уведомления за 3 дня
    $schedule->command('modules:notify-trial-ending', ['--days' => 3])->daily();
    
    // Отправляем уведомления за 1 день
    $schedule->command('modules:notify-trial-ending', ['--days' => 1])->daily();
}
```

#### 6. Notifications

```php
<?php
// app/Notifications/TrialEndingSoonNotification.php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\OrganizationModuleActivation;

class TrialEndingSoonNotification extends Notification
{
    use Queueable;

    protected OrganizationModuleActivation $activation;
    protected int $daysLeft;

    public function __construct(OrganizationModuleActivation $activation, int $daysLeft)
    {
        $this->activation = $activation;
        $this->daysLeft = $daysLeft;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $moduleName = $this->activation->module->name;
        
        return (new MailMessage)
            ->subject("Осталось {$this->daysLeft} дней trial периода")
            ->line("Ваш trial период модуля \"{$moduleName}\" заканчивается через {$this->daysLeft} дней.")
            ->line("Чтобы продолжить использование, активируйте платную подписку.")
            ->action('Активировать модуль', url("/modules/{$this->activation->module->slug}/activate"))
            ->line("Стоимость: {$this->activation->module->getPrice()} ₽/месяц");
    }

    public function toArray($notifiable)
    {
        return [
            'module_slug' => $this->activation->module->slug,
            'module_name' => $this->activation->module->name,
            'days_left' => $this->daysLeft,
            'trial_ends_at' => $this->activation->trial_ends_at,
        ];
    }
}
```

#### 7. API Endpoints

```php
<?php
// routes/api.php или модульные routes

// Активировать trial
Route::post('/modules/{slug}/activate-trial', [ModuleController::class, 'activateTrial'])
    ->middleware(['auth:api_admin']);

// Проверить доступность trial
Route::get('/modules/{slug}/trial-availability', [ModuleController::class, 'checkTrialAvailability'])
    ->middleware(['auth:api_admin']);

// Конвертировать trial в платную версию
Route::post('/modules/{slug}/convert-trial', [ModuleController::class, 'convertTrialToPaid'])
    ->middleware(['auth:api_admin']);
```

```php
<?php
// app/Http/Controllers/Api/V1/Admin/ModuleController.php

public function activateTrial(Request $request, string $slug): JsonResponse
{
    try {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        $moduleManager = app(\App\Modules\Core\ModuleManager::class);
        $result = $moduleManager->activateTrial($organizationId, $slug);
        
        return response()->json($result);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}

public function checkTrialAvailability(Request $request, string $slug): JsonResponse
{
    $user = $request->user();
    $organizationId = $user->current_organization_id;
    
    $module = Module::where('slug', $slug)->firstOrFail();
    $moduleManager = app(\App\Modules\Core\ModuleManager::class);
    
    $hasUsedTrial = $moduleManager->hasUsedTrial($organizationId, $module->id);
    $trialDays = $module->pricing_config['trial_days'] ?? 14;
    
    return response()->json([
        'success' => true,
        'trial_available' => !$hasUsedTrial,
        'trial_days' => $trialDays,
        'has_used_trial' => $hasUsedTrial
    ]);
}

public function convertTrialToPaid(Request $request, string $slug): JsonResponse
{
    try {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        $moduleManager = app(\App\Modules\Core\ModuleManager::class);
        
        // Активируем платную версию (с списанием денег)
        $result = $moduleManager->activate($organizationId, $slug, [
            'activate_trial' => false // Принудительная платная активация
        ]);
        
        return response()->json($result);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);
    }
}
```

#### 8. Event классы

```php
<?php
// app/Events/TrialActivated.php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrialActivated
{
    use Dispatchable, SerializesModels;

    public int $organizationId;
    public string $moduleSlug;
    public int $trialDays;

    public function __construct(int $organizationId, string $moduleSlug, int $trialDays)
    {
        $this->organizationId = $organizationId;
        $this->moduleSlug = $moduleSlug;
        $this->trialDays = $trialDays;
    }
}
```

---

## UI элементы

### Frontend компонент "Попробовать бесплатно"

```vue
<template>
  <div class="module-card">
    <h3>{{ module.name }}</h3>
    <p>{{ module.description }}</p>
    
    <div class="pricing">
      <span class="price">{{ module.price }} ₽/мес</span>
    </div>
    
    <!-- Trial кнопка -->
    <div v-if="!module.is_activated && trialAvailable" class="trial-section">
      <button @click="activateTrial" class="btn-trial">
        🎁 Попробовать {{ module.trial_days }} дней бесплатно
      </button>
      <p class="trial-note">Без привязки карты</p>
    </div>
    
    <!-- Активирован trial -->
    <div v-else-if="module.status === 'trial'" class="trial-active">
      <div class="trial-badge">Trial активен</div>
      <p>Осталось {{ daysLeft }} дней</p>
      <button @click="convertToPaid" class="btn-activate">
        Активировать за {{ module.price }} ₽/мес
      </button>
    </div>
    
    <!-- Trial использован -->
    <div v-else-if="!trialAvailable" class="trial-used">
      <p class="trial-used-note">Trial период был использован</p>
      <button @click="activate" class="btn-activate">
        Активировать за {{ module.price }} ₽/мес
      </button>
    </div>
    
    <!-- Уже активирован -->
    <div v-else-if="module.is_activated">
      <div class="badge-active">✓ Активен</div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      trialAvailable: true,
      daysLeft: 0
    }
  },
  async mounted() {
    await this.checkTrialAvailability()
  },
  methods: {
    async checkTrialAvailability() {
      const response = await this.$api.get(`/modules/${this.module.slug}/trial-availability`)
      this.trialAvailable = response.data.trial_available
    },
    async activateTrial() {
      try {
        await this.$api.post(`/modules/${this.module.slug}/activate-trial`)
        this.$toast.success('Trial период активирован!')
        this.$emit('refresh')
      } catch (error) {
        this.$toast.error(error.response?.data?.message || 'Ошибка активации')
      }
    },
    async convertToPaid() {
      try {
        await this.$api.post(`/modules/${this.module.slug}/convert-trial`)
        this.$toast.success('Модуль активирован!')
        this.$emit('refresh')
      } catch (error) {
        this.$toast.error(error.response?.data?.message || 'Ошибка активации')
      }
    }
  }
}
</script>
```

---

## Оценка трудозатрат

| Задача | Время |
|--------|-------|
| 1. Метод activateTrial() | 2 часа |
| 2. Проверка hasUsedTrial() | 1 час |
| 3. Обновление activate() | 2 часа |
| 4. Command для проверки истекших trial | 3 часа |
| 5. Notifications (2 шт) | 3 часа |
| 6. API endpoints (3 шт) | 3 часа |
| 7. UI компонент | 4 часа |
| 8. Events & Listeners | 2 часа |
| 9. Тестирование | 4 часа |
| **Итого** | **24 часа (3 дня)** |

---

## Чек-лист готовности

- [ ] Метод `activateTrial()` реализован
- [ ] Проверка `hasUsedTrial()` работает
- [ ] Command `ConvertExpiredTrials` создана и добавлена в schedule
- [ ] Уведомления за 3 и 1 день работают
- [ ] API endpoints созданы и протестированы
- [ ] UI показывает кнопку "Попробовать бесплатно"
- [ ] UI показывает оставшиеся дни trial
- [ ] Автоматическая деактивация после окончания trial
- [ ] Тесты написаны и проходят

---

**Статус**: Готово к разработке  
**Приоритет**: Высокий (нужно для монетизации Advanced Dashboard)  
**Дата**: 4 октября 2025

