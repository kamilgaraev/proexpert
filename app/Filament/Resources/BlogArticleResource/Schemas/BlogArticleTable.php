<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogCategory;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;

final class BlogArticleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->marketing()->with(['category', 'systemAuthor']))
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Статья')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Категория')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                Tables\Columns\TextColumn::make('systemAuthor.name')
                    ->label('Автор')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Публикация')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_autosaved_at')
                    ->label('Автосохранение')
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Черновик',
                        'published' => 'Опубликована',
                        'scheduled' => 'Запланирована',
                        'archived' => 'Архив',
                    ]),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => BlogCategory::query()->marketing()->pluck('name', 'id')->all()),
            ])
            ->actions([
                EditAction::make()->label('Открыть редактор'),
                BlogArticleResource::guardedArticleDeleteAction(),
            ]);
    }
}
