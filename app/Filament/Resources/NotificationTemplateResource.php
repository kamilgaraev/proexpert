<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\BusinessModules\Features\Notifications\Models\NotificationTemplate;
use App\Filament\Resources\NotificationTemplateResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\Concerns\HasDestructiveActionGuardrails;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\TableEmptyState;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Policies\SystemAdmin\NotificationTemplatePolicy;
use App\Services\Filament\NotificationTemplateManagementService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class NotificationTemplateResource extends Resource
{
    use AuthorizesSystemAdminResource;
    use HasDestructiveActionGuardrails;

    protected static ?string $model = NotificationTemplate::class;

    protected static string $systemAdminPolicy = NotificationTemplatePolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return NavigationGroups::notifications();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('notifications.templates_navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('notifications.template_model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('notifications.template_plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('notifications.template_section_main'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(trans_message('notifications.template_field_name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('type')
                            ->label(trans_message('notifications.template_field_type'))
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('channel')
                            ->label(trans_message('notifications.template_field_channel'))
                            ->options(fn (): array => self::channelOptions())
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('organization_id')
                            ->label(trans_message('notifications.template_field_organization'))
                            ->options(fn (): array => Organization::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->nullable(),
                        Forms\Components\TextInput::make('locale')
                            ->label(trans_message('notifications.template_field_locale'))
                            ->required()
                            ->maxLength(10)
                            ->default('ru'),
                        Forms\Components\TextInput::make('version')
                            ->label(trans_message('notifications.template_field_version'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Forms\Components\Toggle::make('is_default')
                            ->label(trans_message('notifications.template_field_is_default'))
                            ->default(false),
                        Forms\Components\Toggle::make('is_active')
                            ->label(trans_message('notifications.template_field_is_active'))
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make(trans_message('notifications.template_section_content'))
                    ->schema([
                        Forms\Components\TextInput::make('subject')
                            ->label(trans_message('notifications.template_field_subject'))
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('content')
                            ->label(trans_message('notifications.template_field_content'))
                            ->required()
                            ->rows(10)
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('variables')
                            ->label(trans_message('notifications.template_field_variables'))
                            ->keyLabel(trans_message('notifications.template_field_variable_key'))
                            ->valueLabel(trans_message('notifications.template_field_variable_description'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'notification_templates', 'heroicon-o-envelope-open')
            ->modifyQueryUsing(fn ($query) => $query->with('organization'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(trans_message('notifications.template_column_name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label(trans_message('notifications.template_column_type'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('channel')
                    ->label(trans_message('notifications.template_column_channel'))
                    ->formatStateUsing(fn (?string $state): string => self::channelOptions()[(string) $state] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('notifications.template_column_organization'))
                    ->placeholder(trans_message('notifications.template_column_organization_global'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('locale')
                    ->label(trans_message('notifications.template_column_locale'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')
                    ->label(trans_message('notifications.template_column_version'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label(trans_message('notifications.template_column_is_default'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label(trans_message('notifications.template_column_is_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(trans_message('notifications.template_column_updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label(trans_message('notifications.template_filter_channel'))
                    ->options(fn (): array => self::channelOptions()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(trans_message('notifications.template_filter_is_active')),
                Tables\Filters\TernaryFilter::make('is_default')
                    ->label(trans_message('notifications.template_filter_is_default')),
            ])
            ->actions([
                Action::make('preview')
                    ->label(trans_message('notifications.template_preview_action'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(trans_message('notifications.template_preview_heading'))
                    ->modalSubmitAction(false)
                    ->visible(fn (NotificationTemplate $record): bool => self::canPreviewTemplate($record))
                    ->modalContent(fn (NotificationTemplate $record): View => view(
                        'filament.notifications.template-preview',
                        [
                            'preview' => app(NotificationTemplateManagementService::class)
                                ->preview($record, self::currentSystemAdmin()),
                        ],
                    )),
                Action::make('send_test')
                    ->label(trans_message('notifications.template_send_test_action'))
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('notifications.template_send_test_heading'))
                    ->modalDescription(trans_message('notifications.template_send_test_description'))
                    ->modalSubmitActionLabel(trans_message('notifications.template_send_test_confirm'))
                    ->visible(fn (NotificationTemplate $record): bool => self::canSendTestTemplate($record))
                    ->action(function (NotificationTemplate $record): void {
                        app(NotificationTemplateManagementService::class)->sendTest(
                            $record,
                            self::currentSystemAdmin(),
                        );
                        FilamentNotification::make()
                            ->success()
                            ->title(trans_message('notifications.template_test_sent'))
                            ->send();
                    }),
                Action::make('send_to_audience')
                    ->label(trans_message('notifications.template_send_to_audience_action'))
                    ->icon('heroicon-o-megaphone')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(trans_message('notifications.template_send_to_audience_heading'))
                    ->modalDescription(trans_message('notifications.template_send_to_audience_description'))
                    ->modalSubmitActionLabel(trans_message('notifications.template_send_to_audience_confirm'))
                    ->schema([
                        Forms\Components\Select::make('audience')
                            ->label(trans_message('notifications.template_send_audience_field'))
                            ->options([
                                'selected_users' => trans_message('notifications.template_send_audience_selected_users'),
                                'all_users' => trans_message('notifications.template_send_audience_all_users'),
                            ])
                            ->default('selected_users')
                            ->live()
                            ->required()
                            ->helperText(trans_message('notifications.template_send_audience_help')),
                        Forms\Components\Select::make('recipient_user_ids')
                            ->label(trans_message('notifications.template_send_recipients_field'))
                            ->options(fn (NotificationTemplate $record): array => self::recipientUserOptions($record))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->getSearchResultsUsing(
                                fn (string $search, NotificationTemplate $record): array => self::recipientUserOptions(
                                    $record,
                                    $search,
                                ),
                            )
                            ->getOptionLabelsUsing(
                                fn (mixed $values, NotificationTemplate $record): array => self::recipientUserLabels(
                                    $record,
                                    self::optionLabelValues($values),
                                ),
                            )
                            ->required(fn (Get $get): bool => $get('audience') === 'selected_users')
                            ->visible(fn (Get $get): bool => $get('audience') === 'selected_users')
                            ->helperText(trans_message('notifications.template_send_recipients_help')),
                    ])
                    ->visible(fn (NotificationTemplate $record): bool => self::canSendAudienceTemplate($record))
                    ->action(function (array $data, NotificationTemplate $record): void {
                        self::sendToAudience($record, $data);
                    }),
                EditAction::make(),
                self::guardedDeleteAction('notification_template'),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationTemplates::route('/'),
            'create' => Pages\CreateNotificationTemplate::route('/create'),
            'edit' => Pages\EditNotificationTemplate::route('/{record}/edit'),
        ];
    }

    public static function recipientUserOptions(?NotificationTemplate $template = null, ?string $search = null): array
    {
        return self::recipientUserQuery($template, $search)
            ->limit(50)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                (int) $user->id => self::formatUserOption($user),
            ])
            ->all();
    }

    public static function recipientUserLabels(?NotificationTemplate $template, array $values): array
    {
        $userIds = array_values(array_filter(array_map(
            static fn (mixed $userId): int => is_numeric($userId) ? (int) $userId : 0,
            $values,
        ), static fn (int $userId): bool => $userId > 0));

        if ($userIds === []) {
            return [];
        }

        return self::recipientUserQuery($template)
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                (int) $user->id => self::formatUserOption($user),
            ])
            ->all();
    }

    private static function canPreviewTemplate(NotificationTemplate $template): bool
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        return $systemAdmin instanceof SystemAdmin
            && app(NotificationTemplatePolicy::class)->preview($systemAdmin, $template);
    }

    private static function canSendTestTemplate(NotificationTemplate $template): bool
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        return $systemAdmin instanceof SystemAdmin
            && app(NotificationTemplatePolicy::class)->sendTest($systemAdmin, $template);
    }

    private static function canSendAudienceTemplate(NotificationTemplate $template): bool
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        return $systemAdmin instanceof SystemAdmin
            && app(NotificationTemplatePolicy::class)->sendToAudience($systemAdmin, $template);
    }

    private static function sendToAudience(NotificationTemplate $template, array $data): void
    {
        $service = app(NotificationTemplateManagementService::class);
        $systemAdmin = self::currentSystemAdmin();
        $audience = (string) ($data['audience'] ?? 'selected_users');

        try {
            $result = $audience === 'all_users'
                ? $service->sendToAllUsers($template, $systemAdmin)
                : $service->sendToUsers($template, $systemAdmin, (array) ($data['recipient_user_ids'] ?? []));
        } catch (DomainException $exception) {
            FilamentNotification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            return;
        }

        if ((int) $result['sent_count'] === 0) {
            FilamentNotification::make()
                ->warning()
                ->title(trans_message('notifications.template_broadcast_no_recipients'))
                ->send();

            return;
        }

        FilamentNotification::make()
            ->success()
            ->title(trans_message('notifications.template_broadcast_sent', [
                'count' => (int) $result['sent_count'],
            ]))
            ->send();
    }

    private static function recipientUserQuery(?NotificationTemplate $template = null, ?string $search = null): Builder
    {
        $query = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id');
        $organizationId = is_numeric($template?->organization_id) ? (int) $template->organization_id : null;

        if ($organizationId !== null) {
            $query->whereHas('organizations', function (Builder $organizationQuery) use ($organizationId): void {
                $organizationQuery
                    ->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            });
        }

        if (is_string($search) && trim($search) !== '') {
            $search = trim($search);

            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private static function optionLabelValues(mixed $values): array
    {
        if ($values instanceof \Closure) {
            $values = $values();
        }

        return is_array($values) ? $values : [];
    }

    private static function formatUserOption(User $user): string
    {
        $name = trim((string) $user->name);
        $email = trim((string) $user->email);

        if ($name === '') {
            return $email;
        }

        if ($email === '') {
            return $name;
        }

        return "{$name} <{$email}>";
    }

    private static function channelOptions(): array
    {
        return [
            'in_app' => trans_message('notifications.template_channel_in_app'),
            'email' => trans_message('notifications.template_channel_email'),
            'telegram' => trans_message('notifications.template_channel_telegram'),
            'websocket' => trans_message('notifications.template_channel_websocket'),
        ];
    }

    private static function currentSystemAdmin(): SystemAdmin
    {
        $systemAdmin = Auth::guard('system_admin')->user();

        if (! $systemAdmin instanceof SystemAdmin) {
            throw new RuntimeException('System admin is required for notification template action.');
        }

        return $systemAdmin;
    }
}
