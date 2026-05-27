<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Filament\Resources\ActivityEventResource\Pages;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\SystemAdminAccess;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Services\Activity\ActivityEventRedactor;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

use function trans_message;

class ActivityEventResource extends Resource
{
    protected static ?string $model = ActivityEvent::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return trans_message('activity.audit_resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('activity.audit_resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('activity.audit_resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('activity.audit_resource.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(trans_message('activity.audit_resource.sections.event'))
                    ->schema([
                        Infolists\Components\TextEntry::make('occurred_at')
                            ->label(trans_message('activity.audit_resource.fields.occurred_at'))
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('severity')
                            ->label(trans_message('activity.audit_resource.fields.severity'))
                            ->formatStateUsing(fn (?string $state): string => self::severityLabel($state))
                            ->badge()
                            ->color(fn (?string $state): string => self::severityColor($state)),
                        Infolists\Components\TextEntry::make('module')
                            ->label(trans_message('activity.audit_resource.fields.module')),
                        Infolists\Components\TextEntry::make('event_type')
                            ->label(trans_message('activity.audit_resource.fields.event_type'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('action')
                            ->label(trans_message('activity.audit_resource.fields.action'))
                            ->formatStateUsing(fn (?string $state): string => self::actionLabel($state)),
                        Infolists\Components\TextEntry::make('result')
                            ->label(trans_message('activity.audit_resource.fields.result')),
                        Infolists\Components\TextEntry::make('title')
                            ->label(trans_message('activity.audit_resource.fields.title'))
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('description')
                            ->label(trans_message('activity.audit_resource.fields.description'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(trans_message('activity.audit_resource.sections.actor'))
                    ->schema([
                        Infolists\Components\TextEntry::make('actor_type')
                            ->label(trans_message('activity.audit_resource.fields.actor_type'))
                            ->formatStateUsing(fn (?string $state): string => self::actorTypeLabel($state)),
                        Infolists\Components\TextEntry::make('actor_name')
                            ->label(trans_message('activity.audit_resource.fields.actor_name'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('actor_email')
                            ->label(trans_message('activity.audit_resource.fields.actor_email'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('interface')
                            ->label(trans_message('activity.audit_resource.fields.interface'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('ip_address')
                            ->label(trans_message('activity.audit_resource.fields.ip_address'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value'))
                            ->copyable(),
                        Infolists\Components\TextEntry::make('correlation_id')
                            ->label(trans_message('activity.audit_resource.fields.correlation_id'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value'))
                            ->copyable(),
                    ])
                    ->columns(2),
                Section::make(trans_message('activity.audit_resource.sections.subject'))
                    ->schema([
                        Infolists\Components\TextEntry::make('organization.name')
                            ->label(trans_message('activity.audit_resource.fields.organization'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('project.name')
                            ->label(trans_message('activity.audit_resource.fields.project'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('subject_type')
                            ->label(trans_message('activity.audit_resource.fields.subject_type'))
                            ->formatStateUsing(fn (?string $state): string => self::subjectTypeLabel($state))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('subject_id')
                            ->label(trans_message('activity.audit_resource.fields.subject_id'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value')),
                        Infolists\Components\TextEntry::make('subject_label')
                            ->label(trans_message('activity.audit_resource.fields.subject_label'))
                            ->placeholder(trans_message('activity.audit_resource.empty_value'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(trans_message('activity.audit_resource.sections.payload'))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('changes')
                            ->label(trans_message('activity.audit_resource.fields.changes'))
                            ->state(fn (ActivityEvent $record): array => self::redactedPayload($record->changes))
                            ->placeholder(trans_message('activity.audit_resource.empty_payload'))
                            ->columnSpanFull(),
                        Infolists\Components\KeyValueEntry::make('context')
                            ->label(trans_message('activity.audit_resource.fields.context'))
                            ->state(fn (ActivityEvent $record): array => self::redactedPayload($record->context))
                            ->placeholder(trans_message('activity.audit_resource.empty_payload'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['organization', 'actor', 'targetUser', 'project'])
                ->latest('occurred_at'))
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label(trans_message('activity.audit_resource.fields.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('severity')
                    ->label(trans_message('activity.audit_resource.fields.severity'))
                    ->formatStateUsing(fn (?string $state): string => self::severityLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::severityColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('module')
                    ->label(trans_message('activity.audit_resource.fields.module'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label(trans_message('activity.audit_resource.fields.event_type'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('action')
                    ->label(trans_message('activity.audit_resource.fields.action'))
                    ->formatStateUsing(fn (?string $state): string => self::actionLabel($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor_name')
                    ->label(trans_message('activity.audit_resource.fields.actor_name'))
                    ->searchable()
                    ->placeholder(trans_message('activity.audit_resource.empty_value')),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('activity.audit_resource.fields.organization'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(trans_message('activity.audit_resource.empty_value')),
                Tables\Columns\TextColumn::make('subject_label')
                    ->label(trans_message('activity.audit_resource.fields.subject_label'))
                    ->searchable()
                    ->wrap()
                    ->placeholder(trans_message('activity.audit_resource.empty_value')),
                Tables\Columns\TextColumn::make('correlation_id')
                    ->label(trans_message('activity.audit_resource.fields.correlation_id'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('actor_type')
                    ->label(trans_message('activity.audit_resource.fields.actor_type'))
                    ->options(self::actorTypeOptions()),
                Tables\Filters\SelectFilter::make('organization_id')
                    ->label(trans_message('activity.audit_resource.fields.organization'))
                    ->options(fn (): array => self::organizationOptions())
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('action')
                    ->label(trans_message('activity.audit_resource.fields.action'))
                    ->options(self::actionOptions()),
                Tables\Filters\SelectFilter::make('severity')
                    ->label(trans_message('activity.audit_resource.fields.severity'))
                    ->options(self::severityOptions()),
                Tables\Filters\SelectFilter::make('module')
                    ->label(trans_message('activity.audit_resource.fields.module'))
                    ->options(fn (): array => self::moduleOptions())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label(trans_message('activity.audit_resource.fields.subject_type'))
                    ->options(fn (): array => self::subjectTypeOptions())
                    ->searchable(),
                Tables\Filters\Filter::make('occurred_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label(trans_message('activity.audit_resource.filters.from')),
                        Forms\Components\DatePicker::make('until')
                            ->label(trans_message('activity.audit_resource.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date))),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityEvents::route('/'),
            'view' => Pages\ViewActivityEvent::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return SystemAdminAccess::can(FilamentPermission::AUDIT_LOGS_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ActivityEvent && self::canViewAny();
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

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function redactedPayload(?array $payload): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        return self::flattenPayload(app(ActivityEventRedactor::class)->redact($payload));
    }

    /**
     * @return array<string, string>
     */
    private static function actorTypeOptions(): array
    {
        return [
            'system_admin' => trans_message('activity.audit_resource.actor_types.system_admin'),
            'user' => trans_message('activity.audit_resource.actor_types.user'),
            'system' => trans_message('activity.audit_resource.actor_types.system'),
        ];
    }

    private static function actorTypeLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('activity.audit_resource.empty_value');
        }

        return self::actorTypeOptions()[$state] ?? $state;
    }

    /**
     * @return array<string, string>
     */
    private static function actionOptions(): array
    {
        $options = [];

        foreach (ActivityActionEnum::cases() as $case) {
            $options[$case->value] = trans_message('activity.audit_resource.actions.' . $case->value);
        }

        return $options;
    }

    private static function actionLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('activity.audit_resource.empty_value');
        }

        return self::actionOptions()[$state] ?? $state;
    }

    /**
     * @return array<string, string>
     */
    private static function severityOptions(): array
    {
        $options = [];

        foreach (ActivitySeverityEnum::cases() as $case) {
            $options[$case->value] = trans_message('activity.audit_resource.severities.' . $case->value);
        }

        return $options;
    }

    private static function severityLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('activity.audit_resource.empty_value');
        }

        return self::severityOptions()[$state] ?? $state;
    }

    private static function severityColor(?string $state): string
    {
        return match ($state) {
            ActivitySeverityEnum::Critical->value => 'danger',
            ActivitySeverityEnum::Warning->value => 'warning',
            ActivitySeverityEnum::Notice->value => 'info',
            default => 'gray',
        };
    }

    /**
     * @return array<int, string>
     */
    private static function organizationOptions(): array
    {
        return Organization::query()
            ->orderBy('name')
            ->limit(100)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function moduleOptions(): array
    {
        return ActivityEvent::query()
            ->whereNotNull('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->mapWithKeys(fn (string $module): array => [$module => $module])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function subjectTypeOptions(): array
    {
        return ActivityEvent::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $subjectType): array => [$subjectType => self::subjectTypeLabel($subjectType)])
            ->all();
    }

    private static function subjectTypeLabel(?string $state): string
    {
        if ($state === null || $state === '') {
            return trans_message('activity.audit_resource.empty_value');
        }

        return class_basename($state);
    }

    /**
     * @param array<string|int, mixed> $payload
     * @return array<string, mixed>
     */
    private static function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

            if (is_array($value)) {
                $flat += self::flattenPayload($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
