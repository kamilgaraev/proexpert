<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Logging\LoggingService;
use App\Services\Logging\AuditLogger;
use App\Services\Logging\BusinessLogger;
use App\Services\Logging\SecurityLogger;
use App\Services\Logging\TechnicalLogger;
use App\Services\Logging\AccessLogger;
use App\Services\Logging\DatabaseCacheLogger;
use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;

class LoggingServiceProvider extends ServiceProvider
{
    /**
     * Регистрация сервисов
     */
    public function register(): void
    {
        // Регистрировать контексты как синглтоны
        $this->app->singleton(RequestContext::class, function ($app) {
            return new RequestContext();
        });

        $this->app->singleton(UserContext::class, function ($app) {
            return new UserContext();
        });

        $this->app->singleton(PerformanceContext::class, function ($app) {
            return new PerformanceContext();
        });

        // Регистрировать специализированные логгеры
        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class)
            );
        });

        $this->app->singleton(BusinessLogger::class, function ($app) {
            return new BusinessLogger(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class)
            );
        });

        $this->app->singleton(SecurityLogger::class, function ($app) {
            return new SecurityLogger(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class)
            );
        });

        $this->app->singleton(TechnicalLogger::class, function ($app) {
            return new TechnicalLogger(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class)
            );
        });

        $this->app->singleton(AccessLogger::class, function ($app) {
            return new AccessLogger(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class)
            );
        });

        // Регистрировать основной LoggingService
        $this->app->singleton(LoggingService::class, function ($app) {
            return new LoggingService(
                $app->make(RequestContext::class),
                $app->make(UserContext::class),
                $app->make(PerformanceContext::class),
                $app->make(AuditLogger::class),
                $app->make(BusinessLogger::class),
                $app->make(SecurityLogger::class),
                $app->make(TechnicalLogger::class),
                $app->make(AccessLogger::class)
            );
        });

        // Регистрируем DatabaseCacheLogger как синглтон
        $this->app->singleton(DatabaseCacheLogger::class);
        
        // Регистрируем слушатели DB событий после создания экземпляра
        $this->app->afterResolving(DatabaseCacheLogger::class, function ($logger) {
            $logger->registerListeners();
        });

        // Алиасы для удобства использования
        $this->app->alias(LoggingService::class, 'logging');
        $this->app->alias(AuditLogger::class, 'audit.logger');
        $this->app->alias(BusinessLogger::class, 'business.logger');
        $this->app->alias(SecurityLogger::class, 'security.logger');
        $this->app->alias(TechnicalLogger::class, 'technical.logger');
        $this->app->alias(AccessLogger::class, 'access.logger');
    }

    /**
     * Загрузка сервисов
     */
    public function boot(): void
    {
        // Настройка каналов логирования
        $this->configureLoggingChannels();

        // Регистрация facade, если нужно
        if (config('logging.enable_facades', false)) {
            $this->registerFacades();
        }
    }

    /**
     * Настроить каналы логирования
     */
    protected function configureLoggingChannels(): void
    {
        $config = config('logging.channels', []);

        // Канал для аудита (отдельное хранение для compliance)
        if (!isset($config['audit'])) {
            config([
                'logging.channels.audit' => [
                    'driver' => 'daily',
                    'path' => storage_path('logs/audit/audit.log'),
                    'level' => 'info',
                    'days' => 365, // Хранить год для compliance
                    'permission' => 0644,
                ]
            ]);
        }

        // Канал для безопасности
        if (!isset($config['security'])) {
            config([
                'logging.channels.security' => [
                    'driver' => 'daily',
                    'path' => storage_path('logs/security/security.log'),
                    'level' => 'warning',
                    'days' => 90,
                    'permission' => 0644,
                ]
            ]);
        }

        // Канал для бизнес-событий
        if (!isset($config['business'])) {
            config([
                'logging.channels.business' => [
                    'driver' => 'daily',
                    'path' => storage_path('logs/business/business.log'),
                    'level' => 'info',
                    'days' => 30,
                    'permission' => 0644,
                ]
            ]);
        }
    }

    /**
     * Регистрировать facade'ы для логгеров
     */
    protected function registerFacades(): void
    {
        // Можно добавить facade для удобного использования
        // ProHelperLog::audit('event', []);
        // ProHelperLog::business('event', []);
        // и т.д.
    }

    /**
     * Получить сервисы, предоставляемые провайдером
     */
    public function provides(): array
    {
        return [
            LoggingService::class,
            AuditLogger::class,
            BusinessLogger::class,
            SecurityLogger::class,
            TechnicalLogger::class,
            AccessLogger::class,
            RequestContext::class,
            UserContext::class,
            PerformanceContext::class,
            'logging',
            'audit.logger',
            'business.logger',
            'security.logger',
            'technical.logger',
            'access.logger'
        ];
    }
}
