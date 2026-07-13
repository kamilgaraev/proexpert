<?php

declare(strict_types=1);

namespace App\Filament\Resources\EstimateGeneration\SessionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = null;

    public function table(Table $table): Table
    {
        return $table
            ->heading(trans_message('estimate_generation.sessions.relations.documents'))
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'id', 'session_id', 'filename', 'mime_type', 'status', 'processing_stage',
                'progress_percent', 'file_size_bytes', 'page_count', 'processed_page_count',
                'quality_score', 'quality_level', 'error_code', 'created_at', 'updated_at',
            ]))
            ->defaultSort('id', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID'),
                Tables\Columns\TextColumn::make('filename')->label(trans_message('estimate_generation.sessions.filename'))->limit(80),
                Tables\Columns\TextColumn::make('mime_type')->label(trans_message('estimate_generation.sessions.document_type')),
                Tables\Columns\TextColumn::make('status')->label(trans_message('estimate_generation.sessions.status'))->badge(),
                Tables\Columns\TextColumn::make('progress_percent')->label(trans_message('estimate_generation.sessions.progress'))->suffix('%'),
                Tables\Columns\TextColumn::make('page_count')->label(trans_message('estimate_generation.sessions.pages')),
                Tables\Columns\TextColumn::make('quality_score')->label(trans_message('estimate_generation.sessions.quality')),
                Tables\Columns\TextColumn::make('error_code')->label(trans_message('estimate_generation.sessions.error_code')),
            ]);
    }
}
