<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Modules\Module;
use Atrium\Admin\Modules\Modules;
use Atrium\Search\Indexer;
use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Search;
use Atrium\Search\Searchables;
use Atrium\Search\Tests\Fixtures\Event;
use Atrium\Search\Tests\Fixtures\Leaflet;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * What the index takes from a record: its own document, the configured
 * fields in each language, nothing from models outside the search.
 */
final class SourcesTest extends TestCase
{
    private function withLeaflets(): void
    {
        config(['atrium.public_urls' => [...config('atrium.public_urls'), Leaflet::class => ['prefix' => 'leaflets']]]);
        app(Searchables::class)->flush();
    }

    public function test_a_model_with_a_document_of_its_own_is_found_by_it_in_each_language(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->withLeaflets();

        $leaflet = Leaflet::query()->create(['name' => 'Spring sale', 'code' => 'spring-sale', 'description' => 'Tulips half price']);
        Leaflet::query()->create(['name' => 'Untitled draft', 'code' => 'draft', 'description' => 'Tulips']);

        $entries = SearchEntry::query()->where('searchable_type', Leaflet::class)->get();

        // English only: German has no document, the draft no title.
        $this->assertCount(1, $entries);
        $this->assertSame($leaflet->id, $entries[0]->searchable_id);
        $this->assertSame('en', $entries[0]->language);
        $this->assertSame('Spring sale leaflet', $entries[0]->title);
        $this->assertSame('Tulips half price', $entries[0]->content);
        $this->assertSame([], app(Searchables::class)->all()[Leaflet::class]['content']);
    }

    public function test_a_translatable_model_whose_fields_are_not_translated_has_one_document_for_every_language(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        config(['atrium.search' => [Post::class => ['title' => 'sort', 'content' => []]]]);
        app(Searchables::class)->flush();

        $post = $this->makePost(['en' => 'Kettle news', 'de' => 'Wasserkocher'], ['sort' => 42]);
        $documents = app(Searchables::class)->documents($post);

        $this->assertSame(['' => ['language' => null, 'title' => '42', 'content' => '']], $documents);
    }

    public function test_a_translated_record_without_a_page_in_a_language_is_not_indexed_in_it(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);

        $this->makePost(['en' => 'Kettle news', 'de' => 'Wasserkocher'], ['code' => ['en' => 'kettle-news']]);

        $this->assertSame(['en'], SearchEntry::query()->where('searchable_type', Post::class)->pluck('language')->all());
    }

    public function test_records_of_models_outside_the_search_are_left_alone(): void
    {
        $outside = new class extends Model
        {
            protected $table = 'search_test_products';

            protected $guarded = [];
        };

        $this->assertSame([], app(Searchables::class)->documents($outside));

        app(Indexer::class)->index($outside->forceFill(['id' => 7, 'name' => 'Kettle', 'code' => 'kettle']));

        $this->assertSame(0, SearchEntry::query()->count());
    }

    public function test_the_rebuild_of_one_model_leaves_the_others_as_they_are(): void
    {
        $this->product('Kettle');
        $this->makePost(['en' => 'Kettle news']);
        SearchEntry::query()->delete();

        $this->artisan('atrium:search:rebuild', ['--model' => '\\'.Product::class])->expectsOutputToContain('Indexed 1 records.')->assertSuccessful();

        $this->assertSame([Product::class], SearchEntry::query()->pluck('searchable_type')->all());
    }

    public function test_a_model_without_a_document_in_one_language_is_still_found_in_the_next(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        $this->withLeaflets();

        Leaflet::query()->create(['name' => 'Spring sale', 'code' => 'spring-sale']);

        $this->assertSame(['en', 'fr'], SearchEntry::query()->orderBy('id')->pluck('language')->all());
    }

    public function test_a_language_without_a_title_is_left_out_even_when_it_comes_first(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);

        $german = $this->makePost(['de' => 'Wasserkocher']);
        $both = $this->makePost(['en' => 'Kettle news', 'de' => 'Wasserkocher']);

        $this->assertSame(['de'], array_keys(app(Searchables::class)->documents($german)));
        $this->assertSame(['en', 'de'], array_keys(app(Searchables::class)->documents($both)));
    }

    public function test_a_translated_title_makes_one_document_per_language_whatever_the_text(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        config(['atrium.search' => [Post::class => ['title' => 'name', 'content' => ['sort']]]]);
        app(Searchables::class)->flush();

        $post = $this->makePost(['en' => 'Kettle news', 'de' => 'Wasserkocher'], ['sort' => 42]);

        $this->assertSame([
            'en' => ['language' => 'en', 'title' => 'Kettle news', 'content' => '42'],
            'de' => ['language' => 'de', 'title' => 'Wasserkocher', 'content' => '42'],
        ], app(Searchables::class)->documents($post));
    }

    public function test_the_list_of_models_is_worked_out_once_until_it_is_flushed(): void
    {
        $searchables = app(Searchables::class);
        $first = $searchables->all();

        config(['atrium.search' => [Product::class => false]]);

        $this->assertSame($first, app(Searchables::class)->all());

        $searchables->flush();

        $this->assertSame([Post::class], array_keys(app(Searchables::class)->all()));
    }

    public function test_an_empty_search_setting_leaves_every_model_with_pages(): void
    {
        config(['atrium.search' => null]);
        app(Searchables::class)->flush();

        $this->assertSame([Post::class, Product::class], array_keys(app(Searchables::class)->all()));
    }

    public function test_a_rule_is_read_leniently(): void
    {
        config(['atrium.search' => [
            Product::class => ['title' => 7, 'content' => ['main' => 'description', 'more' => 8]],
            Post::class => ['content' => 'detail_text'],
        ]]);
        app(Searchables::class)->flush();

        $this->assertSame([
            Post::class => ['title' => 'name', 'content' => ['detail_text']],
            Product::class => ['title' => '7', 'content' => ['description', '8']],
        ], app(Searchables::class)->all());
    }

    public function test_a_class_is_found_with_or_without_the_leading_backslash(): void
    {
        config(['atrium.search' => ['\\'.Event::class => ['title' => 'name', 'content' => []]]]);
        app(Searchables::class)->flush();

        $this->assertTrue(app(Searchables::class)->has(Event::class));
        $this->assertTrue(app(Searchables::class)->has('\\'.Product::class));
        $this->assertFalse(app(Searchables::class)->has('\\'.Leaflet::class));
    }

    public function test_a_rule_written_with_the_leading_backslash_is_followed(): void
    {
        config(['atrium.search' => [
            '\\'.Event::class => ['title' => 'headline', 'content' => ['body']],
            '\\'.Product::class => false,
        ]]);
        app(Searchables::class)->flush();

        $this->assertSame(['title' => 'headline', 'content' => ['body']], app(Searchables::class)->all()[Event::class]);
        $this->assertFalse(app(Searchables::class)->has(Product::class));
        $this->assertTrue(app(Searchables::class)->has(Post::class));
    }

    public function test_a_rule_without_a_class_name_is_left_out(): void
    {
        config(['atrium.search' => [['title' => 'name']]]);
        app(Searchables::class)->flush();

        $this->assertSame([Post::class, Product::class], array_keys(app(Searchables::class)->all()));
    }

    public function test_only_models_whose_module_is_installed_are_searched(): void
    {
        config(['atrium.public_urls' => [...config('atrium.public_urls'), Search::class => ['prefix' => 'search'], Event::class => ['prefix' => 'events']]]);
        app(Searchables::class)->flush();

        $this->assertSame([Post::class, Product::class, Event::class], array_keys(app(Searchables::class)->all()));

        app(Modules::class)->add(new Module(name: 'acme/atrium-events', resources: [Event::class]));
        app(Searchables::class)->flush();

        $this->assertSame([Post::class, Product::class], array_keys(app(Searchables::class)->all()));
    }

    public function test_html_becomes_plain_text(): void
    {
        $this->assertSame(
            'One Two "quoted" \'single\' … < >',
            Searchables::plain('<h2>One</h2><p>Two</p><style>p{}</style> &quot;quoted&quot; &apos;single&apos; &hellip; &lt; &gt;'),
        );
    }
}
