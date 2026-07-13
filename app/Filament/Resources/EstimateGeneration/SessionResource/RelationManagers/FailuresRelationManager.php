<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FailuresRelationManager extends RelationManager
{
    protected static string $relationship = 'failures';

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.failures'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'id', 'session_id', 'stage', 'operation', 'provider', 'model', 'category',
                'code', 'occurrence_count', 'first_seen_at', 'last_seen_at', 'resolved_at',
            ]))
            ->defaultSort('last_seen_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('operation')->label(trans_message('estimate_generation.sessions.operation')),
                Tables\Columns\TextColumn::make('category')->label(trans_message('estimate_generation.sessions.category'))->badge(),
                Tables\Columns\TextColumn::make('code')->label(trans_message('estimate_generation.sessions.error_code')),
                Tables\Columns\TextColumn::make('occurrence_count')->label(trans_message('estimate_generation.sessions.occurrences')),
                Tables\Columns\TextColumn::make('last_seen_at')->label(trans_message('estimate_generation.sessions.last_seen_at'))->dateTime(),
                Tables\Columns\TextColumn::make('resolved_at')->label(trans_message('estimate_generation.sessions.resolved_at'))->dateTime(),
            ]);
    }
}
