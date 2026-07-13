<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsageRelationManager extends RelationManager
{
    protected static string $relationship = 'aiUsage';

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.usage'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'attempt_id', 'session_id', 'stage', 'operation', 'attempt_ordinal', 'provider',
                'requested_model', 'reported_model', 'usage_status', 'status', 'input_tokens',
                'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'image_count',
                'page_count', 'duration_ms', 'cost_amount', 'currency', 'pricing_status', 'created_at',
            ]))
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('stage')->label(trans_message('estimate_generation.sessions.stage'))->badge(),
                Tables\Columns\TextColumn::make('provider')->label(trans_message('estimate_generation.dashboard.provider')),
                Tables\Columns\TextColumn::make('requested_model')->label(trans_message('estimate_generation.dashboard.model'))->limit(50),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('duration_ms')->label(trans_message('estimate_generation.sessions.duration_ms'))->numeric(),
                Tables\Columns\TextColumn::make('cost_amount')->label(trans_message('estimate_generation.dashboard.total_cost'))->numeric(8),
                Tables\Columns\TextColumn::make('currency')->label(trans_message('estimate_generation.sessions.currency')),
                Tables\Columns\TextColumn::make('created_at')->label(trans_message('estimate_generation.sessions.created_at'))->dateTime(),
            ]);
    }
}
