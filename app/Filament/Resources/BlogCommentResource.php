<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogCommentResource\Pages;
use App\Models\Blog\BlogComment;
use App\Models\SystemAdmin;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BlogCommentResource extends Resource
{
    protected static ?string $model = BlogComment::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string | \UnitEnum | null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

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
        return $table
            ->modifyQueryUsing(fn ($query) => $query->marketing()->with('article'))
            ->columns([
                Tables\Columns\TextColumn::make('article.title')->label('Статья')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('author_name')->label('Автор')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->since(),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->visible(fn (BlogComment $record): bool => $record->status->value !== 'approved')
                    ->action(function (BlogComment $record): void {
                        /** @var SystemAdmin $systemAdmin */
                        $systemAdmin = Auth::guard('system_admin')->user();
                        $record->approveBySystemAdmin($systemAdmin);
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->action(fn (BlogComment $record) => $record->reject()),
                Action::make('spam')
                    ->label('Spam')
                    ->action(fn (BlogComment $record) => $record->markAsSpam()),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogComments::route('/'),
        ];
    }
}
