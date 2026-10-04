<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Monitoring\TracingService;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class TracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TracingService::class, static fn (): TracingService => TracingService::fromConfig());
    }

    public function boot(): void
    {
        $tracing = $this->app->make(TracingService::class);
        DB::listen(static function (QueryExecuted $event): void {
            if (app()->bound('request')) {
                ApiQueryMetrics::record(app('request'), (float) $event->time);
            }
        });
        if (config('monitoring.tracing_enabled') && $tracing->supportsSpans()) {
            DB::listen(static fn (QueryExecuted $event) => $tracing->recordSql($event));
            Event::listen(CommandExecuted::class, static fn (CommandExecuted $event) => $tracing->recordRedis($event));
            $redis = $this->app->make('redis');
            if ($redis instanceof RedisManager) {
                $redis->enableEvents();
                foreach ($redis->connections() ?? [] as $connection) {
                    $connection->setEventDispatcher($this->app->make('events'));
                }
            }
        }
        Event::listen(CommandStarting::class, static fn (CommandStarting $event) => $tracing->startCommand($event));
        Event::listen(CommandFinished::class, static fn (CommandFinished $event) => $tracing->finishCommand($event));
        $this->app->terminating(static fn () => $tracing->flush());
        Queue::createPayloadUsing(static fn (): array => $tracing->queuePayload());
        Event::listen(JobProcessing::class, static fn (JobProcessing $event) => $tracing->startJob($event));
        Event::listen(JobProcessed::class, static fn (JobProcessed $event) => $tracing->finishJob($event->job));
        Event::listen(JobExceptionOccurred::class, static fn (JobExceptionOccurred $event) => $tracing->finishJob($event->job, $event->exception));
        Event::listen(JobFailed::class, static fn (JobFailed $event) => $tracing->markJobFailed($event->job, $event->exception));
    }
}
