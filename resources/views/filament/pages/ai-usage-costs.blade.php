<x-filament-panels::page>
    @php
        $summary = $report['summary'] ?? [];
        $organizations = $report['organizations'] ?? [];
        $models = $report['models'] ?? [];
        $operations = $report['operations'] ?? [];
        $daily = $report['daily'] ?? [];
    @endphp

    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ trans_message('filament_ai_usage.summary.requests') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->formatTokens($summary['requests_count'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ trans_message('filament_ai_usage.summary.input_tokens') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->formatTokens($summary['input_tokens'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ trans_message('filament_ai_usage.summary.output_tokens') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->formatTokens($summary['output_tokens'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ trans_message('filament_ai_usage.summary.total_tokens') }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->formatTokens($summary['total_tokens'] ?? 0) }}</div>
            </div>
            <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 shadow-sm dark:border-primary-800 dark:bg-primary-950">
                <div class="text-sm font-medium text-primary-700 dark:text-primary-300">{{ trans_message('filament_ai_usage.summary.total_cost') }}</div>
                <div class="mt-2 text-2xl font-semibold text-primary-900 dark:text-primary-100">{{ $this->formatMoney($summary['total_cost_rub'] ?? 0) }}</div>
            </div>
        </div>

        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ trans_message('filament_ai_usage.filters') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_message('filament_ai_usage.period_from') }}</span>
                    <input wire:model="dateFrom" type="date" class="block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_message('filament_ai_usage.period_to') }}</span>
                    <input wire:model="dateTo" type="date" class="block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_message('filament_ai_usage.provider') }}</span>
                    <select wire:model="provider" class="block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                        <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                        @foreach ($this->providerOptions() as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_message('filament_ai_usage.model') }}</span>
                    <select wire:model="model" class="block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                        <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                        @foreach ($this->modelOptions() as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ trans_message('filament_ai_usage.operation') }}</span>
                    <select wire:model="operation" class="block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                        <option value="">{{ trans_message('filament_ai_usage.all_values') }}</option>
                        @foreach ($this->operationOptions() as $option)
                            <option value="{{ $option }}">{{ $this->operationLabel($option) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <button wire:click="applyFilters" type="button" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 disabled:opacity-70" wire:loading.attr="disabled">
                    {{ trans_message('filament_ai_usage.apply') }}
                </button>
                <button wire:click="resetFilters" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-70 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200 dark:hover:bg-gray-900" wire:loading.attr="disabled">
                    {{ trans_message('filament_ai_usage.reset') }}
                </button>
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ trans_message('filament_ai_usage.tables.organizations') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2">{{ trans_message('filament_ai_usage.columns.organization') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.input_tokens') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.output_tokens') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($organizations as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $row['organization_name'] ?? '' }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['input_tokens'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['output_tokens'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-3 py-6 text-center text-gray-500 dark:text-gray-400" colspan="6">{{ trans_message('filament_ai_usage.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ trans_message('filament_ai_usage.tables.models') }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-3 py-2">{{ trans_message('filament_ai_usage.columns.provider') }}</th>
                                <th class="px-3 py-2">{{ trans_message('filament_ai_usage.columns.model') }}</th>
                                <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                                <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($models as $row)
                                <tr>
                                    <td class="px-3 py-2">{{ $row['provider'] ?? '' }}</td>
                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $row['model'] ?? '' }}</td>
                                    <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-3 py-6 text-center text-gray-500 dark:text-gray-400" colspan="4">{{ trans_message('filament_ai_usage.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ trans_message('filament_ai_usage.tables.operations') }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="px-3 py-2">{{ trans_message('filament_ai_usage.columns.operation') }}</th>
                                <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                                <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                                <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($operations as $row)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $this->operationLabel($row['operation'] ?? '') }}</td>
                                    <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-3 py-6 text-center text-gray-500 dark:text-gray-400" colspan="4">{{ trans_message('filament_ai_usage.empty') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ trans_message('filament_ai_usage.tables.daily') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2">{{ trans_message('filament_ai_usage.columns.date') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.requests') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.total_tokens') }}</th>
                            <th class="px-3 py-2 text-right">{{ trans_message('filament_ai_usage.columns.cost') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($daily as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-950 dark:text-white">{{ $row['date'] ?? '' }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['requests_count'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right">{{ $this->formatTokens($row['total_tokens'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-semibold">{{ $this->formatMoney($row['total_cost_rub'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-3 py-6 text-center text-gray-500 dark:text-gray-400" colspan="4">{{ trans_message('filament_ai_usage.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
