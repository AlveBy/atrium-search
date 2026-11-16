<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search;

use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Modules\Modules;
use Atrium\Search\Console\RebuildCommand;
use Atrium\Search\Http\Controllers\SearchController;
use Atrium\Search\Mcp\SearchSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Throwable;

final class SearchServiceProvider extends ServiceProvider
{
    public const MODULE = 'alveby/atrium-search';

    /**
     * The migration comes from extra.atrium in composer.json, once
     * `atrium:module:install alveby/atrium-search` has run.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-search.php', 'atrium-search');
        $this->app->singleton(Searchables::class);
        $this->app->singleton(Search::class);
        $this->app->singleton(Indexer::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'atrium-search');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'atrium-search');

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class]);

            $this->publishes([
                __DIR__.'/../config/atrium-search.php' => config_path('atrium-search.php'),
            ], 'atrium-search-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/atrium-search'),
            ], 'atrium-search-views');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/atrium-search'),
            ], 'atrium-search-lang');
        }

        // Required but not installed: no index table, nothing to search.
        if (! $this->app->make(Modules::class)->isInstalled(self::MODULE)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/search.php');
        config()->push('atrium.mcp.tools', SearchSite::class);

        // The results page and the box in the header are drawn by the theme.
        $this->app->booted(function (): void {
            if (View::exists('atrium-theme::layout')) {
                $this->registerPublicRoutes();
                config()->push('atrium-theme.header_views', 'atrium-search::box');
            }
        });

        // Lazy: runs per page, after the admin is signed in.
        Inertia::share('adminSearch', function (): ?array {
            $user = auth()->guard((string) config('atrium.auth.guard', 'admin'))->user();

            return $user instanceof AdminUser ? ['url' => route('admin.search.records', [], false)] : null;
        });

        $this->registerIndexing();
    }

    private function registerPublicRoutes(): void
    {
        if ($this->app->routesAreCached() || Route::has('atrium.search')) {
            return;
        }

        Route::middleware('web')->group(function (): void {
            Route::localized(function (): void {
                Route::get('search', SearchController::class)->name('atrium.search');
            });
        });
    }

    /**
     * A record is found as it was last saved. A failure here is reported,
     * not thrown: the editor's save has already succeeded.
     */
    private function registerIndexing(): void
    {
        $sync = function (string $event, array $payload): void {
            $record = $payload[0] ?? null;

            if (! $record instanceof Model || ! $this->app->make(Searchables::class)->has($record)) {
                return;
            }

            try {
                $indexer = $this->app->make(Indexer::class);
                str_starts_with($event, 'eloquent.deleted') ? $indexer->remove($record) : $indexer->index($record);
            } catch (Throwable $e) {
                report($e);
            }
        };

        Event::listen('eloquent.saved: *', $sync);
        Event::listen('eloquent.deleted: *', $sync);
    }
}
