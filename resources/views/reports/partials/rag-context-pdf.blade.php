@php
    $ragSummary = is_string($ragReport['summary'] ?? null) ? trim($ragReport['summary']) : '';
    $ragMode = $ragMode ?? 'supporting';
    $ragBlockTitle = $ragBlockTitle ?? 'Контекст из базы знаний';
@endphp

<div class="block rag-block {{ $ragMode === 'primary' ? 'rag-primary' : '' }}">
    <div class="block-title">{{ $ragBlockTitle }}</div>

    @if($ragSummary !== '')
        <p class="rag-summary">{{ $ragSummary }}</p>
    @endif

    @foreach($ragSections as $ragSection)
        @if(is_array($ragSection))
            @php
                $sourceTitle = (string) ($ragSection['source_title'] ?? $ragSection['title'] ?? 'Источник базы знаний');
                $fact = (string) ($ragSection['fact'] ?? $ragSection['excerpt'] ?? '');
                $meta = array_values(is_array($ragSection['meta'] ?? null) ? $ragSection['meta'] : []);
                $items = array_values(is_array($ragSection['items'] ?? null) ? $ragSection['items'] : []);
            @endphp

            <div class="source-block">
                <div class="source-heading">{{ $sourceTitle }}</div>

                @if($meta !== [])
                    <div class="source-meta">{{ implode(' · ', $meta) }}</div>
                @endif

                @if(trim($fact) !== '')
                    <div class="source-fact">{{ $fact }}</div>
                @elseif($items !== [])
                    <ul class="narrative-list">
                        @foreach($items as $item)
                            @if(is_scalar($item) && trim((string) $item) !== '')
                                <li>{{ $item }}</li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    @endforeach
</div>
