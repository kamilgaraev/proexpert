<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\RelationManagers;

use App\Models\Blog\BlogArticleRevision;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogCmsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BlogArticleRevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Ревизии';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('revision_type')
                    ->label('Тип')
                    ->badge(),
                Tables\Columns\TextColumn::make('editor_version')
                    ->label('Версия'),
                Tables\Columns\TextColumn::make('createdBySystemAdmin.name')
                    ->label('Автор'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создано')
                    ->since(),
            ])
            ->actions([
                Action::make('restore')
                    ->label('Восстановить')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => Auth::guard('system_admin')->user()?->hasSystemPermission('system_admin.blog.revisions.restore') ?? false)
                    ->action(function (BlogArticleRevision $record): void {
                        /** @var SystemAdmin $systemAdmin */
                        $systemAdmin = Auth::guard('system_admin')->user();
                        app(BlogCmsService::class)->restoreRevision($record, $systemAdmin);
                        Notification::make()->success()->title(trans_message('blog_cms.revision_restored'))->send();
                        $this->getOwnerRecord()->refresh();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
