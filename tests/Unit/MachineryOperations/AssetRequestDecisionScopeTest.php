<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class AssetRequestDecisionScopeTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule();
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->schema()->create('asset_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_requires_decision_scope_keeps_pending_and_approved_requests_actionable(): void
    {
        foreach (['pending', 'approved', 'assigned', 'rejected', 'cancelled'] as $status) {
            $this->database->table('asset_requests')->insert([
                'organization_id' => 10,
                'status' => $status,
                'created_at' => '2026-08-17 12:00:00',
                'updated_at' => '2026-08-17 12:00:00',
            ]);
        }

        self::assertSame(
            ['pending', 'approved'],
            AssetRequest::query()->requiresDecision()->orderBy('id')->pluck('status')->all(),
        );
    }
}
