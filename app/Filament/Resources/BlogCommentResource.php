<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogCommentResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\TableEmptyState;
use App\Models\Blog\BlogComment;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\BlogCommentPolicy;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BlogCommentResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = BlogComment::class;

    protected static string $systemAdminPolicy = BlogCommentPolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::blog();
    }

    public static function getNavigationLabel(): string
    {
        return 'Комментарии';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Textarea::make('content')->label('Комментарий')->rows(6)->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'blog_comments', 'heroicon-o-chat-bubble-left-right')
            ->modifyQueryUsing(fn ($query) => $query->marketing()->with('article'))
            ->columns([
                Tables\Columns\TextColumn::make('article.title')->label('Статья')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('author_name')->label('Автор')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->since(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label(trans_message('blog_cms.comment_approve_action'))
                        ->visible(fn (BlogComment $record): bool => $record->status->value !== 'approved')
                        ->action(function (BlogComment $record): void {
                            /** @var SystemAdmin $systemAdmin */
                            $systemAdmin = Auth::guard('system_admin')->user();
                            $record->approveBySystemAdmin($systemAdmin);
                        }),
                    Action::make('reject')
                        ->label(trans_message('blog_cms.comment_reject_action'))
                        ->action(fn (BlogComment $record) => $record->reject()),
                    Action::make('spam')
                        ->label(trans_message('blog_cms.comment_spam_action'))
                        ->action(fn (BlogComment $record) => $record->markAsSpam()),
                    self::guardedDeleteAction('comment'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogComments::route('/'),
        ];
    }
}
