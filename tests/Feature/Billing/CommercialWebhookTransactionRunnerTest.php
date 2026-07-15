<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CommercialWebhookEvent;
use App\Services\Billing\CommercialWebhookTransactionRunner;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

class CommercialWebhookTransactionRunnerTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('commercial_webhook_events');
        Schema::create('commercial_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('event_name');
            $table->string('object_id');
            $table->string('authoritative_status')->nullable();
            $table->string('processing_result');
            $table->string('source_ip');
            $table->string('fingerprint')->unique();
            $table->json('safe_payload')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function test_exact_fingerprint_unique_race_returns_duplicate(): void
    {
        $fingerprint = str_repeat('a', 64);
        $this->event($fingerprint);

        $result = app(CommercialWebhookTransactionRunner::class)->run(
            $fingerprint,
            fn (): never => throw $this->queryException(
                'UNIQUE constraint failed: commercial_webhook_events.fingerprint',
            ),
        );

        $this->assertSame('duplicate', $result);
        $this->assertSame(1, CommercialWebhookEvent::query()->count());
    }

    public function test_unrelated_unique_failure_is_never_swallowed_as_duplicate(): void
    {
        $fingerprint = str_repeat('b', 64);
        $this->event($fingerprint);

        $this->expectException(QueryException::class);

        app(CommercialWebhookTransactionRunner::class)->run(
            $fingerprint,
            fn (): never => throw $this->queryException(
                'UNIQUE constraint failed: commercial_refunds.provider_refund_id',
            ),
        );
    }

    private function event(string $fingerprint): void
    {
        CommercialWebhookEvent::query()->create([
            'provider' => 'yookassa', 'event_name' => 'payment.succeeded', 'object_id' => 'payment-id',
            'authoritative_status' => 'succeeded', 'processing_result' => 'processed',
            'source_ip' => '185.71.76.1', 'fingerprint' => $fingerprint,
            'safe_payload' => [], 'processed_at' => now(),
        ]);
    }

    private function queryException(string $message): QueryException
    {
        return new QueryException('sqlite', 'insert into test values (?)', [], new PDOException($message, 23000));
    }
}
