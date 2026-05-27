<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

use Filament\Infolists;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BlogArticleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Статья')
                    ->schema([
                        Infolists\Components\TextEntry::make('title')
                            ->label('Заголовок'),
                        Infolists\Components\TextEntry::make('slug')
                            ->label('Slug')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Статус')
                            ->badge(),
                        Infolists\Components\TextEntry::make('category.name')
                            ->label('Категория')
                            ->placeholder('Не указано'),
                        Infolists\Components\TextEntry::make('systemAuthor.name')
                            ->label('Автор')
                            ->placeholder('Не указан'),
                    ])
                    ->columns(2),
                Section::make('Публикация')
                    ->schema([
                        Infolists\Components\TextEntry::make('published_at')
                            ->label('Дата публикации')
                            ->dateTime()
                            ->placeholder('Не указано'),
                        Infolists\Components\TextEntry::make('scheduled_at')
                            ->label('План публикации')
                            ->dateTime()
                            ->placeholder('Не указано'),
                        Infolists\Components\TextEntry::make('last_autosaved_at')
                            ->label('Автосохранение')
                            ->dateTime()
                            ->placeholder('Не указано'),
                    ])
                    ->columns(3),
                Section::make(trans_message('blog_cms.editorial_checklist_section'))
                    ->schema([
                        Infolists\Components\ViewEntry::make('editorial_checklist')
                            ->view('filament.blog.article-editor.editorial-checklist')
                            ->hiddenLabel(),
                    ]),
            ]);
    }
}
