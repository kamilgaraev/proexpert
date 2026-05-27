<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SupportRequestResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\ContactForm;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\SupportRequestPolicy;
use App\Services\Filament\SupportWorkspaceService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use UnitEnum;

use function trans_message;

class SupportRequestResource extends Resource
{
    use AuthorizesSystemAdminResource;

    protected static ?string $model = ContactForm::class;

    protected static string $systemAdminPolicy = SupportRequestPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return trans_message('support_workspace.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('support_workspace.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('support_workspace.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('support_workspace.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('support_workspace.sections.request'))
                    ->schema([
                        Infolists\Components\TextEntry::make('subject')
                            ->label(trans_message('support_workspace.fields.subject'))
                            ->copyable()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('message')
                            ->label(trans_message('support_workspace.fields.message'))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('status')
                            ->label(trans_message('support_workspace.fields.status'))
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::statusColor($state)),
                        Infolists\Components\TextEntry::make('priority')
                            ->label(trans_message('support_workspace.fields.priority'))
                            ->formatStateUsing(fn (?string $state): string => self::priorityLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::priorityColor($state)),
                        Infolists\Components\TextEntry::make('channel')
                            ->label(trans_message('support_workspace.fields.channel'))
                            ->formatStateUsing(fn (?string $state): string => self::channelLabel($state))
                            ->badge(),
                        Infolists\Components\TextEntry::make('last_activity_at')
                            ->label(trans_message('support_workspace.fields.last_activity_at'))
                            ->dateTime()
                            ->placeholder(trans_message('support_workspace.empty_value')),
                    ])
                    ->columns(2),
                Section::make(trans_message('support_workspace.sections.requester'))
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(trans_message('support_workspace.fields.name'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('email')
                            ->label(trans_message('support_workspace.fields.email'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('phone')
                            ->label(trans_message('support_workspace.fields.phone'))
                            ->placeholder(trans_message('support_workspace.empty_value')),
                        Infolists\Components\TextEntry::make('company')
                            ->label(trans_message('support_workspace.fields.company'))
                            ->placeholder(trans_message('support_workspace.empty_value')),
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('support_workspace.fields.organization'))
                            ->placeholder(trans_message('support_workspace.empty_value')),
                        Infolists\Components\TextEntry::make('assignedSystemAdmin.name')
                            ->label(trans_message('support_workspace.fields.assignee'))
                            ->placeholder(trans_message('support_workspace.not_assigned')),
                    ])
                    ->columns(2),
                Section::make(trans_message('support_workspace.sections.internal_notes'))
                    ->schema([
                        Infolists\Components\TextEntry::make('internal_notes')
                            ->label(trans_message('support_workspace.fields.internal_notes'))
                            ->formatStateUsing(fn (mixed $state): string => self::formatInternalNotes($state))
                            ->placeholder(trans_message('support_workspace.no_internal_notes'))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => self::canManageSupport()),
                Section::make(trans_message('support_workspace.sections.technical'))
                    ->schema([
                        Infolists\Components\TextEntry::make('id')
                            ->label('ID')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('page_source')
                            ->label(trans_message('support_workspace.fields.page_source')),
                        Infolists\Components\TextEntry::make('consent_version')
                            ->label(trans_message('support_workspace.fields.consent_version')),
                        Infolists\Components\KeyValueEntry::make('telegram_data')
                            ->label(trans_message('support_workspace.fields.telegram_data'))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('utm_source')
                            ->label(trans_message('support_workspace.fields.utm_source')),
                        Infolists\Components\TextEntry::make('utm_medium')
                            ->label(trans_message('support_workspace.fields.utm_medium')),
                        Infolists\Components\TextEntry::make('utm_campaign')
                            ->label(trans_message('support_workspace.fields.utm_campaign')),
                        Infolists\Components\TextEntry::make('utm_term')
                            ->label(trans_message('support_workspace.fields.utm_term')),
                        Infolists\Components\TextEntry::make('utm_content')
                            ->label(trans_message('support_workspace.fields.utm_content')),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => self::canManageSupport()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['organization', 'assignedSystemAdmin'])
                ->latest('last_activity_at')
                ->latest('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('subject')
                    ->label(trans_message('support_workspace.fields.subject'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('support_workspace.fields.organization'))
                    ->placeholder(trans_message('support_workspace.empty_value'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('support_workspace.fields.requester'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(trans_message('support_workspace.fields.email'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label(trans_message('support_workspace.fields.priority'))
                    ->formatStateUsing(fn (?string $state): string => self::priorityLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::priorityColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('support_workspace.fields.status'))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('channel')
                    ->label(trans_message('support_workspace.fields.channel'))
                    ->formatStateUsing(fn (?string $state): string => self::channelLabel($state))
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('assignedSystemAdmin.name')
                    ->label(trans_message('support_workspace.fields.assignee'))
                    ->placeholder(trans_message('support_workspace.not_assigned'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label(trans_message('support_workspace.fields.last_activity_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(trans_message('support_workspace.empty_value')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(trans_message('support_workspace.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('support_workspace.fields.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('priority')
                    ->label(trans_message('support_workspace.fields.priority'))
                    ->options(self::priorityOptions()),
                Tables\Filters\SelectFilter::make('channel')
                    ->label(trans_message('support_workspace.fields.channel'))
                    ->options(self::channelOptions()),
                Tables\Filters\SelectFilter::make('assigned_system_admin_id')
                    ->label(trans_message('support_workspace.fields.assignee'))
                    ->options(fn (): array => self::systemAdminOptions())
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('support_workspace.fields.organization'))
                    ->options(fn (): array => self::organizationOptions())
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ViewAction::make(),
                ...self::supportRecordActions(),
            ])
            ->bulkActions([])
            ->defaultSort('last_activity_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportRequests::route('/'),
            'view' => Pages\ViewSupportRequest::route('/{record}'),
        ];
    }

    /**
     * @return list<Action>
     */
    public static function supportRecordActions(): array
    {
        return [
            Action::make('assign')
                ->label(trans_message('support_workspace.actions.assign.label'))
                ->icon('heroicon-o-user-plus')
                ->modalHeading(trans_message('support_workspace.actions.assign.heading'))
                ->modalSubmitActionLabel(trans_message('support_workspace.actions.assign.confirm'))
                ->schema([
                    Forms\Components\Select::make('assigned_system_admin_id')
                        ->label(trans_message('support_workspace.fields.assignee'))
                        ->options(fn (): array => self::systemAdminOptions())
                        ->default(fn (ContactForm $record): ?int => $record->assigned_system_admin_id)
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ])
                ->visible(fn (ContactForm $record): bool => self::canManageSupport($record))
                ->action(function (array $data, ContactForm $record): void {
                    self::assignRequest($record, $data);
                }),
            Action::make('change_status')
                ->label(trans_message('support_workspace.actions.change_status.label'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->modalHeading(trans_message('support_workspace.actions.change_status.heading'))
                ->modalSubmitActionLabel(trans_message('support_workspace.actions.change_status.confirm'))
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label(trans_message('support_workspace.fields.status'))
                        ->options(self::statusOptions())
                        ->default(fn (ContactForm $record): ?string => $record->status)
                        ->required(),
                ])
                ->visible(fn (ContactForm $record): bool => self::canManageSupport($record))
                ->action(function (array $data, ContactForm $record): void {
                    self::changeRequestStatus($record, $data);
                }),
            Action::make('add_internal_note')
                ->label(trans_message('support_workspace.actions.add_internal_note.label'))
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->modalHeading(trans_message('support_workspace.actions.add_internal_note.heading'))
                ->modalSubmitActionLabel(trans_message('support_workspace.actions.add_internal_note.confirm'))
                ->schema([
                    Forms\Components\Textarea::make('body')
                        ->label(trans_message('support_workspace.fields.internal_note'))
                        ->required()
                        ->maxLength(2000)
                        ->rows(5),
                ])
                ->visible(fn (ContactForm $record): bool => self::canManageSupport($record))
                ->action(function (array $data, ContactForm $record): void {
                    self::addInternalNote($record, $data);
                }),
            Action::make('link_organization')
                ->label(trans_message('support_workspace.actions.link_organization.label'))
                ->icon('heroicon-o-building-office-2')
                ->modalHeading(trans_message('support_workspace.actions.link_organization.heading'))
                ->modalSubmitActionLabel(trans_message('support_workspace.actions.link_organization.confirm'))
                ->schema([
                    Forms\Components\Select::make('organization_id')
                        ->label(trans_message('support_workspace.fields.organization'))
                        ->options(fn (): array => self::organizationOptions())
                        ->default(fn (ContactForm $record): ?int => $record->organization_id)
                        ->searchable()
                        ->preload()
                        ->nullable(),
                ])
                ->visible(fn (ContactForm $record): bool => self::canManageSupport($record))
                ->action(function (array $data, ContactForm $record): void {
                    self::linkOrganization($record, $data);
                }),
            Action::make('escalate')
                ->label(trans_message('support_workspace.actions.escalate.label'))
                ->icon('heroicon-o-arrow-trending-up')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(trans_message('support_workspace.actions.escalate.heading'))
                ->modalDescription(trans_message('support_workspace.actions.escalate.description'))
                ->modalSubmitActionLabel(trans_message('support_workspace.actions.escalate.confirm'))
                ->visible(fn (ContactForm $record): bool => $record->priority !== ContactForm::PRIORITY_URGENT && self::canManageSupport($record))
                ->action(function (ContactForm $record): void {
                    self::escalateRequest($record);
                }),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            ContactForm::STATUS_NEW => trans_message('support_workspace.statuses.new'),
            ContactForm::STATUS_PROCESSING => trans_message('support_workspace.statuses.processing'),
            ContactForm::STATUS_COMPLETED => trans_message('support_workspace.statuses.completed'),
            ContactForm::STATUS_CANCELLED => trans_message('support_workspace.statuses.cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function priorityOptions(): array
    {
        return [
            ContactForm::PRIORITY_LOW => trans_message('support_workspace.priorities.low'),
            ContactForm::PRIORITY_NORMAL => trans_message('support_workspace.priorities.normal'),
            ContactForm::PRIORITY_HIGH => trans_message('support_workspace.priorities.high'),
            ContactForm::PRIORITY_URGENT => trans_message('support_workspace.priorities.urgent'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function channelOptions(): array
    {
        return [
            ContactForm::CHANNEL_PUBLIC_FORM => trans_message('support_workspace.channels.public_form'),
            ContactForm::CHANNEL_CUSTOMER_PORTAL => trans_message('support_workspace.channels.customer_portal'),
            ContactForm::CHANNEL_MANUAL => trans_message('support_workspace.channels.manual'),
        ];
    }

    private static function statusLabel(?string $status): string
    {
        return self::statusOptions()[$status] ?? trans_message('support_workspace.statuses.unknown');
    }

    private static function priorityLabel(?string $priority): string
    {
        return self::priorityOptions()[$priority] ?? trans_message('support_workspace.priorities.unknown');
    }

    private static function channelLabel(?string $channel): string
    {
        return self::channelOptions()[$channel] ?? trans_message('support_workspace.channels.unknown');
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            ContactForm::STATUS_NEW => 'info',
            ContactForm::STATUS_PROCESSING => 'warning',
            ContactForm::STATUS_COMPLETED => 'success',
            ContactForm::STATUS_CANCELLED => 'gray',
            default => 'gray',
        };
    }

    private static function priorityColor(?string $priority): string
    {
        return match ($priority) {
            ContactForm::PRIORITY_LOW => 'gray',
            ContactForm::PRIORITY_NORMAL => 'info',
            ContactForm::PRIORITY_HIGH => 'warning',
            ContactForm::PRIORITY_URGENT => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<int, string>
     */
    private static function systemAdminOptions(): array
    {
        return SystemAdmin::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function organizationOptions(): array
    {
        return Organization::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function canManageSupport(?ContactForm $record = null): bool
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if (! $systemAdmin instanceof SystemAdmin) {
            return false;
        }

        if ($record instanceof ContactForm) {
            return app(SupportRequestPolicy::class)->assign($systemAdmin, $record);
        }

        return SystemAdminAccess::can(FilamentPermission::SUPPORT_MANAGE);
    }

    private static function assignRequest(ContactForm $record, array $data): void
    {
        app(SupportWorkspaceService::class)->assign(
            $record,
            self::nullableInt($data['assigned_system_admin_id'] ?? null),
            self::currentSystemAdmin(),
        );

        self::success('support_workspace.actions.assign.success');
    }

    private static function changeRequestStatus(ContactForm $record, array $data): void
    {
        app(SupportWorkspaceService::class)->changeStatus(
            $record,
            (string) ($data['status'] ?? ContactForm::STATUS_NEW),
            self::currentSystemAdmin(),
        );

        self::success('support_workspace.actions.change_status.success');
    }

    private static function addInternalNote(ContactForm $record, array $data): void
    {
        app(SupportWorkspaceService::class)->addInternalNote(
            $record,
            (string) ($data['body'] ?? ''),
            self::currentSystemAdmin(),
        );

        self::success('support_workspace.actions.add_internal_note.success');
    }

    private static function linkOrganization(ContactForm $record, array $data): void
    {
        app(SupportWorkspaceService::class)->linkOrganization(
            $record,
            self::nullableInt($data['organization_id'] ?? null),
            self::currentSystemAdmin(),
        );

        self::success('support_workspace.actions.link_organization.success');
    }

    private static function escalateRequest(ContactForm $record): void
    {
        app(SupportWorkspaceService::class)->escalate($record, self::currentSystemAdmin());

        self::success('support_workspace.actions.escalate.success');
    }

    private static function success(string $translationKey): void
    {
        Notification::make()
            ->success()
            ->title(trans_message($translationKey))
            ->send();
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function currentSystemAdmin(): SystemAdmin
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if (! $systemAdmin instanceof SystemAdmin) {
            throw new RuntimeException('System admin is required for support workspace action.');
        }

        return $systemAdmin;
    }

    private static function formatInternalNotes(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return trans_message('support_workspace.no_internal_notes');
        }

        return collect($state)
            ->filter(fn (mixed $note): bool => is_array($note) && trim((string) ($note['body'] ?? '')) !== '')
            ->map(function (array $note): string {
                $author = trim((string) ($note['author_name'] ?? trans_message('support_workspace.unknown_author')));
                $createdAt = trim((string) ($note['created_at'] ?? ''));
                $body = trim((string) ($note['body'] ?? ''));

                return trim(sprintf('%s %s: %s', $createdAt, $author, $body));
            })
            ->implode(PHP_EOL . PHP_EOL);
    }
}
