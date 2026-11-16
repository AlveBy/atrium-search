<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Atrium\Search\Http\Controllers\AdminSearchController;
use Atrium\Search\Http\Controllers\ApiSearchController;
use Illuminate\Support\Facades\Route;

// The editors' box in the admin header.
Route::prefix(config('atrium.prefix', 'admin'))
    ->middleware([...config('atrium.middleware', ['web']), 'atrium.site', 'atrium.inertia', 'admin.auth', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('search/records', AdminSearchController::class)->name('admin.search.records');
    });

// The visitors' search for API tokens (and the MCP tool search_site). Not
// "search": that would be read as a module of the API.
Route::prefix(config('atrium.api.prefix', 'api/v1'))
    ->middleware(['api', 'atrium.site', 'admin.api', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('site-search', ApiSearchController::class)->name('admin.api.site-search');
    });
