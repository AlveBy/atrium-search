{{--
    This file is part of Atrium (https://github.com/AlveBy/atrium).
    Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
--}}
<ul class="search-results">
    @foreach ($hits as $hit)
        <li data-search-hit>
            <h3><a href="{{ $hit['url'] }}">{{ $hit['title_html'] }}</a></h3>
            @if ((string) $hit['snippet'] !== '')
                <p>{{ $hit['snippet'] }}</p>
            @endif
        </li>
    @endforeach
</ul>
