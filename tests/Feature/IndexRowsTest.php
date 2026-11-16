<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Search;
use Atrium\Search\Searchables;
use Atrium\Search\Tests\Fixtures\Event;
use Atrium\Search\Tests\Fixtures\Post;
use Atrium\Search\Tests\TestCase;

/**
 * What a row of the index holds: the words in lower case, texts cut to
 * size, the activity, the dates and the address of the record.
 */
final class IndexRowsTest extends TestCase
{
    private function withEvents(): void
    {
        config(['atrium.search' => [Event::class => ['title' => 'name', 'content' => ['description']]]]);
        app(Searchables::class)->flush();
    }

    public function test_the_words_are_the_title_and_the_text_in_lower_case(): void
    {
        $this->product('Большой ЧАЙНИК', 'Медный, на 2 литра');

        $this->assertSame('большой чайник медный, на 2 литра', SearchEntry::query()->sole()->words);
    }

    public function test_long_titles_and_texts_are_cut_by_letters(): void
    {
        $this->withEvents();
        Event::query()->create(['name' => str_repeat('Ж', 501), 'description' => str_repeat('ж', 60001), 'url' => '/events/long']);

        $entry = SearchEntry::query()->sole();

        $this->assertSame(str_repeat('Ж', 500), $entry->title);
        $this->assertSame(str_repeat('ж', 60000), $entry->content);
        $this->assertSame(str_repeat('ж', 500).' '.str_repeat('ж', 60000), $entry->words);
    }

    public function test_activity_stored_as_a_number_is_read_as_yes_or_no(): void
    {
        $this->withEvents();
        Event::query()->create(['name' => 'Kettle fair', 'url' => '/events/fair', 'active' => 2]);
        Event::query()->create(['name' => 'Kettle market', 'url' => '/events/market', 'active' => 0]);

        $this->assertSame([Event::class => 1], app(Search::class)->counts('kettle'));
        $this->assertSame(['Kettle fair'], array_column(app(Search::class)->paginate('kettle')->items(), 'title'));
    }

    public function test_the_row_keeps_the_dates_of_the_record_or_the_time_of_indexing(): void
    {
        $this->travelTo('2026-05-04 10:00:00');
        $this->product('Kettle', '', ['created_at' => '2025-01-02 03:04:05', 'updated_at' => '2025-02-03 04:05:06']);
        $this->withEvents();
        Event::query()->create(['name' => 'Kettle fair', 'url' => '/events/fair']);

        $product = SearchEntry::query()->where('searchable_type', '!=', Event::class)->sole();
        $event = SearchEntry::query()->where('searchable_type', Event::class)->sole();

        $this->assertSame(['2025-01-02 03:04:05', '2025-02-03 04:05:06'], [$product->created_at?->toDateTimeString(), $product->updated_at?->toDateTimeString()]);
        $this->assertSame(['2026-05-04 10:00:00', '2026-05-04 10:00:00'], [$event->created_at?->toDateTimeString(), $event->updated_at?->toDateTimeString()]);
    }

    public function test_the_address_is_kept_without_the_host_with_its_query(): void
    {
        $this->withEvents();

        foreach ([
            'https://fairs.example/events/kettle?day=2' => '/events/kettle?day=2',
            'https://fairs.example/events/kettle' => '/events/kettle',
            'https://fairs.example' => '/',
            '/events/teapot?day=3' => '/events/teapot?day=3',
        ] as $url => $kept) {
            $event = Event::query()->create(['name' => 'Fair', 'url' => $url]);

            $this->assertSame($kept, SearchEntry::query()->where('searchable_id', $event->id)->sole()->url, $url);
        }
    }

    public function test_a_record_without_a_page_in_the_default_language_is_indexed_in_the_others(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);

        $this->makePost(['en' => 'Kettle news', 'de' => 'Wasserkocher', 'fr' => 'Bouilloire'], ['code' => ['de' => 'wasserkocher', 'fr' => 'bouilloire']]);

        $this->assertSame(['de', 'fr'], SearchEntry::query()->where('searchable_type', Post::class)->orderBy('language')->pluck('language')->all());
    }
}
