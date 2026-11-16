{{--
    This file is part of Atrium (https://github.com/AlveBy/atrium).
    Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
--}}
@extends('atrium-theme::layout', [
    'metaTitle' => $query === '' ? __('atrium-search::search.title') : __('atrium-search::search.title_for', ['query' => $query]),
])

@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    <h1>{{ __('atrium-search::search.title') }}</h1>

    <form class="search-form" role="search" method="get" action="{{ $action }}" data-search-form>
        <input type="search" name="q" value="{{ $query }}" aria-label="{{ __('atrium-search::search.label') }}" maxlength="200" @if ($query === '') autofocus @endif>
        @if ($in !== null)
            <input type="hidden" name="in" value="{{ request()->query('in') }}">
        @endif
        <button type="submit">{{ __('atrium-search::search.submit') }}</button>
    </form>

    @if ($tooShort)
        <p class="empty" data-search-short>{{ __('atrium-search::search.too_short', ['min' => config('atrium-search.min_length', 2)]) }}</p>
    @elseif ($searched && $total === 0)
        <p class="empty" data-search-empty>{{ __('atrium-search::search.nothing', ['query' => $query]) }}</p>
    @elseif ($searched)
        <p data-search-total>{{ __('atrium-search::search.found', ['count' => $total]) }}</p>

        @if (count($filters) > 1 || $in !== null)
            <ul class="sections" aria-label="{{ __('atrium-search::search.modules') }}" data-search-filters>
                <li><a href="{{ $allUrl }}"@if ($in === null) aria-current="true"@endif>{{ __('atrium-search::search.everywhere') }} ({{ $total }})</a></li>
                @foreach ($filters as $filter)
                    <li><a href="{{ $filter['url'] }}" data-search-filter="{{ $filter['key'] }}"@if ($filter['current']) aria-current="true"@endif>{{ $filter['label'] }} ({{ $filter['count'] }})</a></li>
                @endforeach
            </ul>
        @endif

        @if ($results !== null)
            @include('atrium-search::partials.hits', ['hits' => $results->items()])
            @include('atrium-theme::partials.pagination', ['paginator' => $results])
        @else
            @foreach ($groups as $group)
                <section data-search-group="{{ $group['key'] }}">
                    <h2>{{ $group['label'] }}</h2>
                    @include('atrium-search::partials.hits', ['hits' => $group['hits']])
                    @if ($group['count'] > count($group['hits']))
                        <p><a href="{{ collect($filters)->firstWhere('key', $group['key'])['url'] }}" data-search-more>{{ __('atrium-search::search.all_results', ['count' => $group['count']]) }}</a></p>
                    @endif
                </section>
            @endforeach
        @endif
    @endif
@endsection
