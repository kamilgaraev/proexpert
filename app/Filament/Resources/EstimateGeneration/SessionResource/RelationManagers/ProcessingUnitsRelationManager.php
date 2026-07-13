<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProcessingUnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'processingUnits';

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.units'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'id', 'session_id', 'document_id', 'unit_type', 'unit_index', 'status',
                'attempt_count', 'dispatch_attempt_count', 'output_count', 'lease_expires_at',
                'started_at', 'completed_at', 'failed_at', 'failure_code', 'created_at',
            ]))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('document_id')->label(trans_message('estimate_generation.sessions.document')),
                Tables\Columns\TextColumn::make('unit_type')->label(trans_message('estimate_generation.sessions.unit_type'))->badge(),
                Tables\Columns\TextColumn::make('unit_index')->label(trans_message('estimate_generation.sessions.unit_index')),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('attempt_count')->label(trans_message('estimate_generation.sessions.attempt')),
                Tables\Columns\TextColumn::make('failure_code')->label(trans_message('estimate_generation.sessions.error_code')),
            ]);
    }
}
