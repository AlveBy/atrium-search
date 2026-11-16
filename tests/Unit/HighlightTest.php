<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Unit;

use Atrium\Search\Search;
use Atrium\Search\Tests\Fixtures\Product;
use Atrium\Search\Tests\TestCase;

final class HighlightTest extends TestCase
{
    public function test_marks_are_the_only_markup(): void
    {
        $this->assertSame(
            '&lt;b&gt;<mark>Phone</mark>&lt;/b&gt; &amp; <mark>pho</mark>to',
            (string) Search::highlight('<b>Phone</b> & photo', ['pho', 'phone']),
        );
    }

    public function test_the_snippet_starts_near_the_word_and_keeps_whole_words(): void
    {
        $text = str_repeat('lorem ipsum ', 40).'the kettle boils '.str_repeat('dolor sit ', 40);
        $snippet = (string) Search::snippet($text, ['kettle']);

        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        $this->assertStringContainsString('the <mark>kettle</mark> boils', $snippet);
        $this->assertMatchesRegularExpression('/^…(lorem|ipsum) /u', $snippet);
    }

    public function test_the_snippet_starts_a_third_of_its_length_before_the_first_word_of_the_query_found(): void
    {
        // Words of five characters with the spaces: "kettle" at 200, "w090" at 452.
        $words = array_map(static fn (int $i): string => sprintf('w%03d', $i), range(0, 99));
        $words[40] = 'kettle';
        $text = implode(' ', $words);

        $this->assertStringStartsWith('…w026 w027', (string) Search::snippet($text, ['zzz', 'kettle', 'w090']));
        $this->assertStringStartsWith('…w076 w077', (string) Search::snippet($text, ['w090', 'kettle']));
    }

    public function test_the_snippet_counts_letters_not_bytes(): void
    {
        $words = array_map(static fn (int $i): string => sprintf('я%03d', $i), range(0, 99));
        $words[40] = 'Чайник';
        $snippet = (string) Search::snippet(implode(' ', $words), ['чайник']);

        $this->assertStringStartsWith('…я026 я027', $snippet);
        $this->assertStringContainsString('я039 <mark>Чайник</mark> я041', $snippet);
        $this->assertStringEndsWith(' я068…', $snippet);

        $short = 'чайник '.str_repeat('ж', 143);
        $this->assertSame('<mark>чайник</mark> '.str_repeat('ж', 143), (string) Search::snippet($short, ['чайник']));
    }

    public function test_the_snippet_leaves_no_space_by_the_dots(): void
    {
        $this->assertSame('…<mark>kettle</mark>', (string) Search::snippet(str_repeat('x', 100).'  kettle', ['kettle']));
        $this->assertSame(str_repeat('a', 210).'…', (string) Search::snippet(str_repeat('a', 210).'  '.str_repeat('b', 20), ['kettle']));
    }

    public function test_a_text_as_long_as_the_snippet_is_shown_whole(): void
    {
        $text = 'kettle '.str_repeat('a', 213);

        $this->assertSame('<mark>kettle</mark> '.str_repeat('a', 213), (string) Search::snippet($text, ['kettle']));
        $this->assertSame('<mark>kettle</mark>…', (string) Search::snippet($text.' b', ['kettle']));
    }

    public function test_the_longer_of_two_words_is_marked_whole(): void
    {
        $this->assertSame('<mark>чайник</mark>', (string) Search::highlight('чайник', ['чай', 'чайник']));
        $this->assertSame('<mark>чайники</mark>', (string) Search::highlight('чайники', ['чайники', 'чайник']));
    }

    public function test_signs_in_a_word_are_marked_as_they_are(): void
    {
        $this->assertSame('<mark>a.c</mark> abc <mark>a/c</mark>', (string) Search::highlight('a.c abc a/c', ['a.c', 'a/c']));
    }

    public function test_a_broken_text_is_shown_escaped_without_marks(): void
    {
        $this->assertSame("\u{FFFD}&lt;tea&gt;", (string) Search::highlight("\xFF<tea>", ['tea']));
    }

    public function test_query_words(): void
    {
        $this->assertSame(['red', 'kettle', 'тест', '2020'], app(Search::class)->terms('Red, KETTLE! red тест 2020'));
    }

    public function test_a_query_keeps_its_first_eight_words(): void
    {
        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], app(Search::class)->terms('a b c d e f g h i j'));
    }

    public function test_a_query_is_long_enough_by_its_letters_without_the_spaces_around(): void
    {
        $search = app(Search::class);

        $this->assertFalse($search->accepts(' x '));
        $this->assertFalse($search->accepts('ж'));
        $this->assertFalse($search->accepts('!!'));
        $this->assertTrue($search->accepts('жж'));
        $this->assertTrue($search->accepts(' x2 '));

        config(['atrium-search.min_length' => 3]);

        $this->assertFalse($search->accepts('жж'));
        $this->assertTrue($search->accepts('жжж'));
    }

    public function test_without_words_the_text_is_only_escaped(): void
    {
        $this->assertSame('&lt;b&gt;Tea&lt;/b&gt;', (string) Search::highlight('<b>Tea</b>', ['']));
        $this->assertSame('&lt;b&gt;Tea&lt;/b&gt;', (string) Search::highlight('<b>Tea</b>', []));
    }

    public function test_a_query_too_short_finds_nothing_and_asks_nothing_of_the_database(): void
    {
        $this->product('A kettle');
        $search = app(Search::class);

        $page = $search->paginate('a', perPage: 5);
        $this->assertSame(0, $page->total());
        $this->assertSame(5, $page->perPage());
        $this->assertSame([], $search->top('a', Product::class, 3));
        $this->assertSame([], $search->counts('a'));

        $this->assertSame(1, $search->paginate('kettle')->total());
        $this->assertCount(1, $search->top('kettle', Product::class, 3));
    }
}
