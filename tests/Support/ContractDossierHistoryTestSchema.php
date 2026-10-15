<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ContractDossierHistoryTestSchema
{
    public static function create(Builder $schema): void
    {
        $schema->create('payment_documents', static function (Blueprint $table): void {
            $table->id();
            $table->string('invoiceable_type')->nullable();
            $table->unsignedBigInteger('invoiceable_id')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status');
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('invoice_type')->nullable();
            $table->jsonb('metadata')->nullable();
        });
        $schema->create('payment_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_document_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('status');
            $table->decimal('amount', 18, 2);
        });
        $schema->create('contract_performance_acts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->boolean('is_approved')->default(false);
            $table->string('status')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
        });
        $schema->create('completed_works', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->softDeletes();
        });
        $schema->create('contract_builder_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
        });
    }
}
