<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditEvents';

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.audit'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->select(['id', 'session_id', 'package_id', 'user_id', 'event_type', 'created_at'])
                ->with('user:id,name'))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('event_type')->label(trans_message('estimate_generation.sessions.event'))->badge(),
                Tables\Columns\TextColumn::make('user.name')->label(trans_message('estimate_generation.sessions.actor')),
                Tables\Columns\TextColumn::make('created_at')->label(trans_message('estimate_generation.sessions.created_at'))->dateTime(),
            ]);
    }
}
