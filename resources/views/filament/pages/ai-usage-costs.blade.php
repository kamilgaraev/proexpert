<x-filament-panels::page>
    @php
        $summary = $report['summary'] ?? [];
        $organizations = $report['organizations'] ?? [];
        $models = $report['models'] ?? [];
        $operations = $report['operations'] ?? [];
        $daily = $report['daily'] ?? [];

        $stats = [
            [
                'label' => trans_message('filament_ai_usage.summary.total_cost'),
                'value' => $this->formatMoney($summary['total_cost_rub'] ?? 0),
                'icon' => 'heroicon-o-banknotes',
                'accent' => 'text-primary-600 dark:text-primary-400',
                'featured' => true,
            ],
            [
                'label' => trans_message('filament_ai_usage.summary.requests'),
                'value' => $this->formatTokens($summary['requests_count'] ?? 0),
                'icon' => 'heroicon-o-paper-airplane',
                'accent' => 'text-gray-500 dark:text-gray-400',
                'featured' => false,
            ],
            [
                'label' => trans_message('filament_ai_usage.summary.input_tokens'),
                'value' => $this->formatTokens($summary['input_tokens'] ?? 0),
                'icon' => 'heroicon-o-arrow-down-tray',
                'accent' => 'text-gray-500 dark:text-gray-400',
                'featured' => false,
            ],
            [
                'label' => trans_message('filament_ai_usage.summary.output_tokens'),
                'value' => $this->formatTokens($summary['output_tokens'] ?? 0),
                'icon' => 'heroicon-o-arrow-up-tray',
                'accent' => 'text-gray-500 dark:text-gray-400',
                'featured' => false,
            ],
            [
                'label' => trans_message('filament_ai_usage.summary.total_tokens'),
                'value' => $this->formatTokens($summary['total_tokens'] ?? 0),
                'icon' => 'heroicon-o-circle-stack',
                'accent' => 'text-gray-500 dark:text-gray-400',
                'featured' => false,
            ],
        ];
    @endphp

    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            @foreach ($stats as $stat)
                <x-filament::section
                    :compact="true"
                    class="{{ $stat['featured'] ? 'xl:col-span-2' : 'xl:col-span-1' }}"
                >
                    <div class="flex min-h-20 items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                {{ $stat['label'] }}
                            </div>
                            <div
                                @class([
                                    'mt-2 truncate font-semibold tracking-tight text-gray-950 dark:text-white',
                                    'text-3xl' => $stat['featured'],
                                    'text-2xl' => ! $stat['featured'],
                                ])
                            >
                                {{ $stat['value'] }}
                            </div>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <x-filament::icon :icon="$stat['icon']" class="h-5 w-5 {{ $stat['accent'] }}" />
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section
            :heading="trans_message('filament_ai_usage.filters')"
            icon="heroicon-o-funnel"
            :compact="true"
        >
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ trans_message('filament_ai_usage.period_from') }}</span>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-calendar-days">
                        <x-filament::input type="date" wire:model="dateFrom" />
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ trans_message('filament_ai_usage.period_to') }}</span>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-calendar-days">
                        <x-filament::input type="date" wire:model="dateTo" />
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ trans_message('filament_ai_usage.provider') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="provider">
                            <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                            @foreach ($this->providerOptions() as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ trans_message('filament_ai_usage.model') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="model">
                            <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                            @foreach ($this->modelOptions() as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ trans_message('filament_ai_usage.operation') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="operation">
                            <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                            @foreach ($this->operationOptions() as $option)
                                <option value="{{ $option }}">{{ $this->operationLabel($option) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <x-filament::button wire:click="applyFilters" icon="heroicon-m-check" wire:loading.attr="disabled">
                    {{ trans_message('filament_ai_usage.apply') }}
                </x-filament::button>

                <x-filament::button wire:click="resetFilters" color="gray" icon="heroicon-m-arrow-path" wire:loading.attr="disabled">
                    {{ trans_message('filament_ai_usage.reset') }}
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section
            :heading="trans_message('filament_ai_usage.tables.organizations')"
            icon="heroicon-o-building-office-2"
        >
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">{{ trans_message('filament_ai_usage.columns.organization') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.input_tokens') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.output_tokens') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                        @forelse ($organizations as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['organization_name'] ?? '' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['input_tokens'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['output_tokens'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400" colspan="6">
                                    {{ trans_message('filament_ai_usage.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section
                :heading="trans_message('filament_ai_usage.tables.models')"
                icon="heroicon-o-cpu-chip"
            >
                <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">{{ trans_message('filament_ai_usage.columns.provider') }}</th>
                                <th class="px-4 py-3">{{ trans_message('filament_ai_usage.columns.model') }}</th>
                                <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                                <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                            @forelse ($models as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $row['provider'] ?? '' }}</td>
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['model'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400" colspan="4">
                                        {{ trans_message('filament_ai_usage.empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            <x-filament::section
                :heading="trans_message('filament_ai_usage.tables.operations')"
                icon="heroicon-o-squares-2x2"
            >
                <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-4 py-3">{{ trans_message('filament_ai_usage.columns.operation') }}</th>
                                <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                                <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                                <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                            @forelse ($operations as $row)
                                <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $this->operationLabel($row['operation'] ?? '') }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400" colspan="4">
                                        {{ trans_message('filament_ai_usage.empty') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section
            :heading="trans_message('filament_ai_usage.tables.daily')"
            icon="heroicon-o-calendar-days"
        >
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full divide-y divide-gray-950/5 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">{{ trans_message('filament_ai_usage.columns.date') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                            <th class="px-4 py-3 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                        @forelse ($daily as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['date'] ?? '' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400" colspan="4">
                                    {{ trans_message('filament_ai_usage.empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
