<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Search;
use Atrium\Search\Searchables;
use Atrium\Search\Tests\Fixtures\Event;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * What the search service gives back: the order of the results, what each
 * result holds and where it links to.
 */
final class ResultsTest extends TestCase
{
    public function test_results_with_the_first_word_in_the_title_come_first(): void
    {
        $this->product('Big kettle set', 'Copper handle', ['updated_at' => now()->subDay()]);
        $this->product('Copper pot', 'Fits a kettle');

        $this->assertSame(['Big kettle set', 'Copper pot'], array_column(app(Search::class)->paginate('kettle copper')->items(), 'title'));
        $this->assertSame(['Copper pot', 'Big kettle set'], array_column(app(Search::class)->paginate('copper kettle')->items(), 'title'));
    }

    public function test_a_result_says_what_it_is_and_links_to_the_page(): void
    {
        $kettle = $this->product('Copper kettle', 'Boils water');

        $this->assertSame([
            'module' => 'products',
            'model' => Product::class,
            'id' => $kettle->id,
            'label' => 'Products',
            'title' => 'Copper kettle',
            'url' => 'http://localhost/shop/copper-kettle',
        ], Arr::except(app(Search::class)->top('kettle', Product::class, 1)[0], ['snippet', 'title_html']));
    }

    public function test_a_model_outside_the_admin_is_named_after_its_class(): void
    {
        config(['atrium.search' => [Event::class => ['title' => 'name', 'content' => []]]]);
        app(Searchables::class)->flush();
        $fair = Event::query()->create(['name' => 'Kettle fair', 'url' => 'https://fairs.example/kettle']);

        $this->assertSame([
            'module' => 'events',
            'model' => Event::class,
            'id' => $fair->id,
            'label' => 'Event',
            'title' => 'Kettle fair',
            'url' => 'https://fairs.example/kettle',
        ], Arr::except(app(Search::class)->top('kettle', Event::class, 1)[0], ['snippet', 'title_html']));
        $this->assertSame(Event::class, app(Search::class)->model('events'));
    }

    public function test_a_result_links_to_the_page_the_record_has_now(): void
    {
        $hose = $this->product('Garden hose');
        DB::table('search_test_products')->where('id', $hose->id)->update(['code' => 'green-hose']);

        $this->assertSame('http://localhost/shop/green-hose', app(Search::class)->top('hose', Product::class, 1)[0]['url']);

        // Gone around the model: the address the index kept.
        DB::table('search_test_products')->where('id', $hose->id)->delete();

        $this->assertSame('http://localhost/shop/garden-hose', app(Search::class)->top('hose', Product::class, 1)[0]['url']);
    }

    public function test_only_the_models_asked_for_and_searched_are_looked_in(): void
    {
        $this->product('Kettle');
        $search = app(Search::class);

        $this->assertSame(1, $search->paginate('kettle', [Product::class])->total());
        $this->assertSame(0, $search->paginate('kettle', [Post::class])->total());

        config(['atrium.search' => [Product::class => false]]);
        app(Searchables::class)->flush();

        $this->assertSame(0, $search->paginate('kettle', [Product::class])->total());
        $this->assertSame(0, $search->paginate('kettle')->total());
    }

    public function test_a_page_has_as_many_results_as_the_settings_say(): void
    {
        $this->product('Kettle');

        $this->assertSame(10, app(Search::class)->paginate('kettle')->perPage());
        $this->assertSame(10, app(Search::class)->paginate('x')->perPage());

        config(['atrium-search.per_page' => 3]);

        $this->assertSame(3, app(Search::class)->paginate('kettle')->perPage());
        $this->assertSame(7, app(Search::class)->paginate('kettle', perPage: 7)->perPage());
    }
}
