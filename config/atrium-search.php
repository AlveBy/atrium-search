<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | What is searched
    |--------------------------------------------------------------------------
    |
    | Every module with public pages is searched: the title is its `name`, the
    | text its text, textarea and visual editor fields. A module tells more in
    | composer.json (extra.atrium.search), and the app has the last word in
    | config('atrium.search'):
    |
    |     'search' => [
    |         App\Models\Article::class => ['title' => 'name', 'content' => ['preview_text', 'detail_text']],
    |         App\Models\Vacancy::class => false,   // never searched
    |     ],
    |
    | After changing it, run `php artisan atrium:search:rebuild`.
    |
    */

    // Results per page of one module.
    'per_page' => 10,

    // Results of each module on the first page, before "all results".
    'group_limit' => 5,

    // Shorter queries are not searched.
    'min_length' => 2,
];
