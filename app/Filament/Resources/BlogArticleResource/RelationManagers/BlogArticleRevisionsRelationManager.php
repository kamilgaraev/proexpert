<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\RelationManagers;

use App\Filament\Support\TableEmptyState;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticleRevision;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogCmsService;
use App\Services\Blog\BlogRevisionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class BlogArticleRevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans_message('blog_cms.revisions_title');
    }

    public function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'blog_revisions', 'heroicon-o-clock')
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('revision_type')
                    ->label(trans_message('blog_cms.revision_type_column'))
                    ->formatStateUsing(fn (?BlogRevisionTypeEnum $state): string => $state?->label() ?? '')
                    ->badge(),
                Tables\Columns\TextColumn::make('editor_version')
                    ->label(trans_message('blog_cms.revision_version_column')),
                Tables\Columns\TextColumn::make('createdBySystemAdmin.name')
                    ->label(trans_message('blog_cms.revision_changed_by_column')),
                Tables\Columns\TextColumn::make('changed_fields')
                    ->label(trans_message('blog_cms.revision_changed_fields_column'))
                    ->getStateUsing(fn (BlogArticleRevision $record): string => app(BlogRevisionService::class)
                        ->changedFieldSummary($record, $this->getOwnerRecord()))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(trans_message('blog_cms.revision_created_at_column'))
                    ->since(),
            ])
            ->actions([
                Action::make('restore')
                    ->label(trans_message('blog_cms.revision_restore_action'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('blog_cms.revision_restore_heading'))
                    ->modalDescription(trans_message('blog_cms.revision_restore_description'))
                    ->modalSubmitActionLabel(trans_message('blog_cms.revision_restore_confirm'))
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === BlogArticleStatusEnum::DRAFT
                        && (
                            Auth::guard('system_admin')
                                ->user()
                                ?->hasSystemPermission('system_admin.blog.revisions.restore') ?? false
                        ))
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
