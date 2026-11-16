<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Search\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

/**
 * What a visitor finds on /search: records of this site, in this language,
 * published today, grouped by module.
 */
final class SearchPageTest extends TestCase
{
    public function test_a_saved_record_is_found_with_the_words_marked(): void
    {
        $this->product('Blue phone case', '<p>Fits every <b>phone</b> sold since 2020 & keeps it safe.</p>');
        $this->product('Desk lamp', 'Warm light.');

        $this->get('/search?q=Phone')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<a href="http://localhost/shop/blue-phone-case">Blue <mark>phone</mark> case</a>', false)
            ->assertSee('Fits every <mark>phone</mark> sold since 2020 &amp; keeps it safe.', false)
            ->assertDontSee('Desk lamp');
    }

    public function test_every_word_must_be_found_and_prefixes_count(): void
    {
        $this->product('Blue phone case');
        $this->product('Red phone case');

        $this->get('/search?q=blue+phones')->assertOk()->assertSee('data-search-empty', false);
        $this->get('/search?q=blue+pho')->assertOk()->assertSee('<mark>Blue</mark> <mark>pho</mark>ne case', false)->assertDontSee('Red phone');
    }

    public function test_inactive_unpublished_and_expired_records_are_not_found(): void
    {
        $hidden = $this->product('Hidden lantern', '', ['active' => false]);
        $this->makePost(['en' => 'Lantern festival'], ['active_from' => now()->addDay()]);
        $this->makePost(['en' => 'Lantern sale'], ['active_to' => now()->subDay()]);
        $this->makePost(['en' => 'Lantern parade'], ['active_from' => now()->subDay(), 'active_to' => now()->addDay()]);

        $this->get('/search?q=lantern')
            ->assertOk()
            ->assertSee('Lantern</mark> parade', false)
            ->assertDontSee('festival')
            ->assertDontSee('Lantern</mark> sale', false)
            ->assertDontSee('Hidden');

        // Saving makes the index follow.
        $hidden->update(['active' => true]);
        $this->get('/search?q=lantern')->assertSee('Hidden <mark>lantern</mark>', false);

        $hidden->delete();
        $this->get('/search?q=hidden')->assertSee('data-search-empty', false);
    }

    public function test_each_language_finds_its_own_text(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->makePost(['en' => 'Morning news', 'de' => 'Morgennachrichten'], ['detail_text' => ['en' => 'Coffee', 'de' => 'Kaffee']]);
        $this->product('Morning coffee mug');

        $this->get('/search?q=morning')
            ->assertOk()
            ->assertSee('href="http://localhost/news/morning-news"', false)
            ->assertSee('Morning</mark> coffee mug', false);

        $this->get('/de/search?q=kaffee')
            ->assertOk()
            ->assertSee('<html lang="de">', false)
            ->assertSee('href="http://localhost/de/news/morgennachrichten"', false);

        // The English text is not on the German page, the shared product is.
        $this->get('/de/search?q=morning')
            ->assertOk()
            ->assertDontSee('morning-news')
            ->assertSee('Morning</mark> coffee mug', false);
    }

    public function test_records_of_another_site_are_not_found(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->makePost(['en' => 'Harbour opening'], ['site_id' => $second->id]);
        $this->product('Harbour map');

        $this->get('/search?q=harbour')
            ->assertOk()
            ->assertSee('Harbour</mark> map', false)
            ->assertDontSee('opening');

        $this->get('/site2/search?q=harbour')
            ->assertOk()
            ->assertSee('Harbour</mark> opening', false)
            ->assertSee('href="http://localhost/site2/news/harbour-opening"', false)
            ->assertSee('Harbour</mark> map', false);
    }

    public function test_results_are_grouped_by_module_and_paged(): void
    {
        config(['atrium-search.group_limit' => 2, 'atrium-search.per_page' => 2]);

        foreach (['One', 'Two', 'Three'] as $number) {
            $this->product("Garden lamp {$number}");
        }

        $this->makePost(['en' => 'Garden party']);

        $this->get('/search?q=garden')
            ->assertOk()
            ->assertSee('data-search-total', false)
            ->assertSee('Everywhere (4)')
            ->assertSee('Products (3)')
            ->assertSee('News (1)')
            ->assertSee('data-search-group="products"', false)
            ->assertSee('data-search-group="posts"', false)
            ->assertSee('href="http://localhost/search?q=garden&amp;in=products" data-search-more', false);

        $this->get('/search?q=garden&in=products')
            ->assertOk()
            ->assertSee('data-search-filter="products" aria-current="true">Products (3)', false)
            ->assertDontSee('Garden</mark> party', false)
            ->assertSee('href="http://localhost/search?q=garden&amp;in=products&amp;page=2"', false);

        $this->get('/search?q=garden&in=products&page=2')
            ->assertOk()
            ->assertSee('Page 2 of 2');
    }

    public function test_a_short_or_empty_query_is_not_searched(): void
    {
        $this->product('X ray');

        $this->get('/search')->assertOk()->assertDontSee('data-search-empty', false)->assertDontSee('data-search-short', false);
        $this->get('/search?q=x')->assertOk()->assertSee('data-search-short', false)->assertDontSee('data-search-hit', false);
        $this->get('/search?q='.urlencode('%_'))->assertOk()->assertDontSee('data-search-hit', false);
    }

    public function test_spaces_around_the_query_are_dropped_by_the_page_itself(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $this->product('Desk lamp');

        $this->get('/search?q=+lamp+')
            ->assertOk()
            ->assertSee('name="q" value="lamp" aria-label', false)
            ->assertSee('Desk <mark>lamp</mark>', false);
    }

    public function test_settings_written_as_text_work_as_numbers(): void
    {
        config(['atrium-search.group_limit' => '1', 'atrium-search.per_page' => '1', 'atrium-search.min_length' => '3']);
        $this->product('Garden lamp');
        $this->product('Garden hose');

        $this->get('/search?q=garden')->assertOk()->assertSee('data-search-more', false);
        $this->get('/search?q=garden&in=products')->assertOk()->assertSee('Page 1 of 2');
        $this->get('/search?q=ga')->assertOk()->assertSee('data-search-short', false);
    }

    public function test_the_language_switch_and_the_form_keep_only_what_there_is(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);

        $this->get('/search?q=lamp')
            ->assertOk()
            ->assertSee('href="http://localhost/de/search?q=lamp" hreflang="de"', false)
            ->assertSee('action="http://localhost/search" data-search-form', false);

        $this->get('/search')
            ->assertOk()
            ->assertSee('href="http://localhost/de/search" hreflang="de"', false)
            ->assertSee('action="http://localhost/search" data-search-form', false);
    }

    public function test_the_header_has_a_search_box(): void
    {
        $this->get('/search?q=lamp')
            ->assertOk()
            ->assertSee('action="http://localhost/search" data-search-box', false)
            ->assertSee('name="q" value="lamp"', false);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-search-box', false)
            ->assertSee('name="q" value=""', false);
    }
}
