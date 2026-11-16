<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Indexer;
use Atrium\Search\Search;
use Atrium\Search\Searchables;
use Atrium\Search\SearchServiceProvider;
use Atrium\Search\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

/**
 * What the module brings to the application: its settings, one copy of
 * each service, and the files a site may copy to change.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_the_settings_have_their_defaults(): void
    {
        $this->assertSame(10, config('atrium-search.per_page'));
        $this->assertSame(5, config('atrium-search.group_limit'));
        $this->assertSame(2, config('atrium-search.min_length'));
    }

    public function test_each_service_is_made_once(): void
    {
        $this->assertSame(app(Searchables::class), app(Searchables::class));
        $this->assertSame(app(Search::class), app(Search::class));
        $this->assertSame(app(Indexer::class), app(Indexer::class));
    }

    public function test_the_settings_views_and_translations_can_be_published(): void
    {
        $root = realpath(__DIR__.'/../../src').'/../';

        $this->assertSame(
            [$root.'config/atrium-search.php' => config_path('atrium-search.php')],
            ServiceProvider::pathsToPublish(SearchServiceProvider::class, 'atrium-search-config'),
        );
        $this->assertSame(
            [$root.'resources/views' => resource_path('views/vendor/atrium-search')],
            ServiceProvider::pathsToPublish(SearchServiceProvider::class, 'atrium-search-views'),
        );
        $this->assertSame(
            [$root.'lang' => app()->langPath('vendor/atrium-search')],
            ServiceProvider::pathsToPublish(SearchServiceProvider::class, 'atrium-search-lang'),
        );
    }
}
