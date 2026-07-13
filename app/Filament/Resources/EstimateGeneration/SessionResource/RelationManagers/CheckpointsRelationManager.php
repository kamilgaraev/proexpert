<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CheckpointsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkpoints';

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.checkpoints'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'id', 'session_id', 'generation_attempt_id', 'stage', 'status', 'attempt_count',
                'artifact_bytes', 'lease_expires_at', 'started_at', 'completed_at', 'failed_at',
                'invalidated_at', 'invalidation_reason', 'last_error_code', 'created_at',
            ]))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('attempt_count')->label(trans_message('estimate_generation.sessions.attempt')),
                Tables\Columns\TextColumn::make('started_at')->label(trans_message('estimate_generation.sessions.started_at'))->dateTime(),
                Tables\Columns\TextColumn::make('completed_at')->label(trans_message('estimate_generation.sessions.completed_at'))->dateTime(),
                Tables\Columns\TextColumn::make('last_error_code')->label(trans_message('estimate_generation.sessions.error_code')),
            ]);
    }
}
