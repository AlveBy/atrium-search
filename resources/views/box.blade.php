{{--
    This file is part of Atrium (https://github.com/AlveBy/atrium).
    Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
--}}
{{-- The search box in the theme's header. --}}
@if (Route::has('atrium.search'))
    <form class="search-box" role="search" method="get" action="{{ app(\Atrium\Admin\Support\ContentLanguages::class)->route('atrium.search') }}" data-search-box>
        <input type="search" name="q" value="{{ request()->routeIs('atrium.search', 'localized.atrium.search') ? request()->query('q') : '' }}"
               placeholder="{{ __('atrium-search::search.placeholder') }}" aria-label="{{ __('atrium-search::search.label') }}" maxlength="200">
        <button type="submit">{{ __('atrium-search::search.submit') }}</button>
    </form>
@endif
