<?php

declare(strict_types=1);

namespace App\Filament\Resources\Monitoring;

use App\Enums\Activity\ActivityActionEnum;
use App\Filament\Resources\Monitoring\ApplicationErrorResource\Pages;
use App\Filament\Support\Concerns\AuthorizesSystemAdminResource;
use App\Filament\Support\FilamentPermission;
use App\Filament\Support\NavigationGroups;
use App\Filament\Support\SystemAdminAccess;
use App\Filament\Support\TableEmptyState;
use App\Models\ApplicationError;
use App\Models\SystemAdmin;
use App\Policies\SystemAdmin\ApplicationErrorPolicy;
use App\Services\Filament\SystemAdminAuditService;
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

class ApplicationErrorResource extends Resource
{
    use AuthorizesSystemAdminResource;

    protected static ?string $model = ApplicationError::class;

    protected static string $systemAdminPolicy = ApplicationErrorPolicy::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return NavigationGroups::platform();
    }

    public static function getNavigationLabel(): string
    {
        return trans_message('monitoring.application_errors.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return trans_message('monitoring.application_errors.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans_message('monitoring.application_errors.plural_model_label');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(trans_message('monitoring.application_errors.sections.summary'))
                ->schema([
                    Infolists\Components\TextEntry::make('error_group')
                        ->label(trans_message('monitoring.application_errors.fields.group'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('severity')
                        ->label(trans_message('monitoring.application_errors.fields.severity'))
                        ->formatStateUsing(fn (?string $state): string => self::severityLabel($state))
                        ->badge()
                        ->color(fn (?string $state): string => self::severityColor($state)),
                    Infolists\Components\TextEntry::make('status')
                        ->label(trans_message('monitoring.application_errors.fields.status'))
                        ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                        ->badge()
                        ->color(fn (?string $state): string => self::statusColor($state)),
                    Infolists\Components\TextEntry::make('occurrences')
                        ->label(trans_message('monitoring.application_errors.fields.occurrences')),
                    Infolists\Components\TextEntry::make('last_seen_at')
                        ->label(trans_message('monitoring.application_errors.fields.last_seen_at'))
                        ->dateTime(),
                ])
                ->columns(2),
            Section::make(trans_message('monitoring.application_errors.sections.context'))
                ->schema([
                    Infolists\Components\TextEntry::make('module')
                        ->label(trans_message('monitoring.application_errors.fields.module'))
                        ->placeholder(trans_message('monitoring.common.empty_value')),
                    Infolists\Components\TextEntry::make('message')
                        ->label(trans_message('monitoring.application_errors.fields.message'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('url')
                        ->label(trans_message('monitoring.application_errors.fields.url'))
                        ->placeholder(trans_message('monitoring.common.empty_value'))
                        ->columnSpanFull(),
                    Infolists\Components\TextEntry::make('method')
                        ->label(trans_message('monitoring.application_errors.fields.method'))
                        ->placeholder(trans_message('monitoring.common.empty_value')),
                    Infolists\Components\TextEntry::make('file')
                        ->label(trans_message('monitoring.application_errors.fields.file'))
                        ->formatStateUsing(fn (?string $state): string => self::shortPath($state))
                        ->placeholder(trans_message('monitoring.common.empty_value')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TableEmptyState::for($table, 'application_errors', 'heroicon-o-bug-ant')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['organization', 'user']))
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->label(trans_message('monitoring.application_errors.fields.severity'))
                    ->formatStateUsing(fn (?string $state): string => self::severityLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::severityColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(trans_message('monitoring.application_errors.fields.status'))
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_group')
                    ->label(trans_message('monitoring.application_errors.fields.group'))
                    ->searchable()
                    ->limit(48)
                    ->wrap(),
                Tables\Columns\TextColumn::make('message')
                    ->label(trans_message('monitoring.application_errors.fields.message'))
                    ->searchable()
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('module')
                    ->label(trans_message('monitoring.application_errors.fields.module'))
                    ->placeholder(trans_message('monitoring.common.empty_value'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label(trans_message('monitoring.application_errors.fields.organization'))
                    ->placeholder(trans_message('monitoring.common.empty_value'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('occurrences')
                    ->label(trans_message('monitoring.application_errors.fields.occurrences'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(trans_message('monitoring.application_errors.fields.last_seen_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(trans_message('monitoring.application_errors.fields.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('severity')
                    ->label(trans_message('monitoring.application_errors.fields.severity'))
                    ->options(self::severityOptions()),
                Tables\Filters\SelectFilter::make('module')
                    ->label(trans_message('monitoring.application_errors.fields.module'))
                    ->options(fn (): array => ApplicationError::query()
                        ->whereNotNull('module')
                        ->distinct()
                        ->orderBy('module')
                        ->pluck('module', 'module')
                        ->all()),
                Tables\Filters\Filter::make('last_seen_at')
                    ->label(trans_message('monitoring.application_errors.filters.last_seen_period'))
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label(trans_message('monitoring.application_errors.filters.from')),
                        Forms\Components\DatePicker::make('until')
                            ->label(trans_message('monitoring.application_errors.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyLastSeenFilter($query, $data)),
            ])
            ->actions([
                ViewAction::make(),
                self::statusAction('mark_resolved', 'resolved', 'success'),
                self::statusAction('mark_ignored', 'ignored', 'gray'),
                self::statusAction('mark_unresolved', 'unresolved', 'warning'),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplicationErrors::route('/'),
            'view' => Pages\ViewApplicationError::route('/{record}'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function applyLastSeenFilter(Builder $query, array $data): Builder
    {
        if (is_string($data['from'] ?? null) && $data['from'] !== '') {
            $query->whereDate('last_seen_at', '>=', $data['from']);
        }

        if (is_string($data['until'] ?? null) && $data['until'] !== '') {
            $query->whereDate('last_seen_at', '<=', $data['until']);
        }

        return $query;
    }

    private static function statusAction(string $name, string $status, string $color): Action
    {
        return Action::make($name)
            ->label(trans_message("monitoring.application_errors.actions.{$name}.label"))
            ->icon(trans_message("monitoring.application_errors.actions.{$name}.icon"))
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading(trans_message("monitoring.application_errors.actions.{$name}.heading"))
            ->modalDescription(trans_message("monitoring.application_errors.actions.{$name}.description"))
            ->modalSubmitActionLabel(trans_message("monitoring.application_errors.actions.{$name}.confirm"))
            ->visible(fn (ApplicationError $record): bool => self::canManageStatus($record, $status))
            ->action(function (ApplicationError $record) use ($status): void {
                self::changeStatus($record, $status);
            });
    }

    private static function canManageStatus(ApplicationError $record, string $targetStatus): bool
    {
        return $record->status !== $targetStatus
            && SystemAdminAccess::can(FilamentPermission::MONITORING_MANAGE);
    }

    private static function changeStatus(ApplicationError $record, string $status): void
    {
        $actor = SystemAdminAccess::user();

        if (! $actor instanceof SystemAdmin) {
            return;
        }

        $before = ['status' => $record->status];
        $record->forceFill(['status' => $status])->save();

        app(SystemAdminAuditService::class)->record(
            actor: $actor,
            eventType: 'system_admin.monitoring.error_status_changed',
            action: ActivityActionEnum::Updated,
            subjectType: ApplicationError::class,
            subjectId: (int) $record->getKey(),
            subjectLabel: $record->error_group,
            organizationId: is_numeric($record->organization_id) ? (int) $record->organization_id : null,
            title: trans_message('monitoring.application_errors.audit.status_changed_title'),
            description: trans_message('monitoring.application_errors.audit.status_changed_description', [
                'status' => self::statusLabel($status),
            ]),
            before: $before,
            after: ['status' => $status],
        );

        Notification::make()
            ->success()
            ->title(trans_message('monitoring.application_errors.actions.status_changed'))
            ->send();
    }

    private static function shortPath(?string $path): string
    {
        if ($path === null || $path === '') {
            return trans_message('monitoring.common.empty_value');
        }

        return str_replace((string) base_path(), '', $path);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            'unresolved' => trans_message('monitoring.application_errors.statuses.unresolved'),
            'resolved' => trans_message('monitoring.application_errors.statuses.resolved'),
            'ignored' => trans_message('monitoring.application_errors.statuses.ignored'),
        ];
    }

    private static function statusLabel(?string $status): string
    {
        return self::statusOptions()[$status ?? ''] ?? trans_message('monitoring.common.unknown');
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            'resolved' => 'success',
            'ignored' => 'gray',
            default => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function severityOptions(): array
    {
        return [
            'warning' => trans_message('monitoring.application_errors.severities.warning'),
            'error' => trans_message('monitoring.application_errors.severities.error'),
            'critical' => trans_message('monitoring.application_errors.severities.critical'),
        ];
    }

    private static function severityLabel(?string $severity): string
    {
        return self::severityOptions()[$severity ?? ''] ?? trans_message('monitoring.common.unknown');
    }

    private static function severityColor(?string $severity): string
    {
        return match ($severity) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'gray',
        };
    }
}
