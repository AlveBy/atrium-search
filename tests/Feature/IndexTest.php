<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Facades\Hook;
use Atrium\Search\Indexer;
use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Searchables;
use Atrium\Search\Tests\Fixtures\Leaflet;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;

/**
 * The index follows the models, and the rebuild command catches up with
 * changes made around them.
 */
final class IndexTest extends TestCase
{
    public function test_the_index_keeps_plain_text_and_the_page_path(): void
    {
        $this->product('Garden hose', '<p>Twenty&nbsp;metres, <em>green</em>.</p><script>alert(1)</script>');

        $entry = SearchEntry::query()->sole();

        $this->assertSame(Product::class, $entry->searchable_type);
        $this->assertNull($entry->site_id);
        $this->assertNull($entry->language);
        $this->assertSame('/shop/garden-hose', $entry->url);
        $this->assertStringContainsString('Twenty metres, green.', $entry->content);
        $this->assertStringNotContainsString('alert', $entry->content);
        $this->assertSame('garden hose twenty metres, green.', $entry->words);
    }

    public function test_saving_a_record_again_replaces_its_row(): void
    {
        $kettle = $this->product('Kettle');
        $kettle->update(['name' => 'Copper kettle']);

        $this->assertSame(['Copper kettle'], SearchEntry::query()->pluck('title')->all());
    }

    public function test_records_of_models_outside_the_search_cost_no_query_on_the_index(): void
    {
        $outside = (new class extends Model
        {
            protected $table = 'search_test_products';

            protected $guarded = [];
        })->forceFill(['id' => 7, 'name' => 'Kettle', 'code' => 'kettle']);
        $admin = $this->admin();
        DB::enableQueryLog();

        app(Indexer::class)->index($outside);
        $admin->update(['name' => 'Editor']);
        $admin->delete();

        $this->assertSame([], array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'search_index')));

        $this->product('Kettle')->delete();

        $this->assertNotSame([], array_filter(array_column(DB::getQueryLog(), 'query'), static fn (string $sql): bool => str_contains($sql, 'search_index')));
        $this->assertSame(0, SearchEntry::query()->count());
    }

    public function test_a_row_reads_its_numbers_dates_and_activity_typed(): void
    {
        $entry = (new SearchEntry)->forceFill(['searchable_id' => '7', 'site_id' => '2', 'active' => '0', 'active_from' => '2026-01-02 03:04:05', 'active_to' => null]);

        $this->assertSame(7, $entry->searchable_id);
        $this->assertSame(2, $entry->site_id);
        $this->assertFalse($entry->active);
        $this->assertSame('2026-01-02 03:04:05', $entry->active_from?->toDateTimeString());
        $this->assertNull($entry->active_to);
    }

    public function test_the_rebuild_command_indexes_records_written_around_the_models(): void
    {
        DB::table('search_test_products')->insert(['name' => 'Imported kettle', 'code' => 'imported-kettle', 'active' => true]);

        $this->get('/search?q=kettle')->assertSee('data-search-empty', false);

        $this->artisan('atrium:search:rebuild')->expectsOutputToContain('Indexed 1 records.')->assertSuccessful();

        $this->get('/search?q=kettle')->assertSee('Imported <mark>kettle</mark>', false);
    }

    public function test_a_model_left_out_in_the_config_is_not_searched(): void
    {
        $this->makePost(['en' => 'Kettle news']);
        $this->product('Kettle');

        config(['atrium.search' => [Post::class => false]]);

        $this->artisan('atrium:search:rebuild')->assertSuccessful();
        $this->artisan('atrium:search:rebuild', ['--model' => Post::class])->assertFailed();

        $this->assertSame([Product::class], SearchEntry::query()->pluck('searchable_type')->all());
    }

    public function test_the_config_says_which_fields_are_read(): void
    {
        config(['atrium.search' => [Product::class => ['title' => 'code', 'content' => []]]]);
        $this->artisan('atrium:search:rebuild')->assertSuccessful();
        $this->product('Copper kettle', 'Boils water');

        $entry = SearchEntry::query()->where('searchable_type', Product::class)->sole();

        $this->assertSame('copper-kettle', $entry->title);
        $this->assertSame('', $entry->content);
    }

    public function test_a_package_takes_a_model_out_through_the_sources_filter(): void
    {
        $seen = null;
        Hook::filter('atrium.search.sources', function (array $models) use (&$seen): array {
            $seen = array_keys($models);
            unset($models[Post::class]);

            return $models;
        });
        app(Searchables::class)->flush();

        $this->makePost(['en' => 'Kettle news']);
        $this->product('Kettle');

        $this->assertSame([Post::class, Product::class], $seen);
        $this->assertSame([Product::class], SearchEntry::query()->pluck('searchable_type')->all());
        $this->assertFalse(app(Searchables::class)->has(Post::class));
    }

    public function test_a_package_adds_or_changes_a_model_through_the_sources_filter(): void
    {
        Hook::filter('atrium.search.sources', fn (array $models): array => [
            ...$models,
            Product::class => ['title' => 'code', 'content' => []],
        ]);
        app(Searchables::class)->flush();

        $this->product('Copper kettle', 'Boils water');

        $entry = SearchEntry::query()->where('searchable_type', Product::class)->sole();
        $this->assertSame('copper-kettle', $entry->title);
        $this->assertSame('', $entry->content);
    }

    public function test_without_a_filter_every_model_with_pages_is_searched(): void
    {
        $this->assertFalse(Hook::has('atrium.search.sources'));
        $this->assertSame([Post::class, Product::class], array_keys(app(Searchables::class)->all()));
    }

    public function test_a_failing_index_is_reported_and_the_save_still_succeeds(): void
    {
        Exceptions::fake();
        config(['atrium.public_urls' => [...config('atrium.public_urls'), Leaflet::class => ['prefix' => 'leaflets']]]);
        app(Searchables::class)->flush();

        $leaflet = Leaflet::query()->create(['name' => 'Broken', 'code' => 'broken']);

        $this->assertTrue($leaflet->exists);
        $this->assertSame(1, Leaflet::query()->count());
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Index down');
    }

    public function test_a_saved_record_is_indexed_without_reporting_anything(): void
    {
        Exceptions::fake();

        $this->product('Kettle');

        $this->assertSame(1, SearchEntry::query()->count());
        Exceptions::assertNothingReported();
    }
}
