<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Atrium\Search\Matcher;
use Atrium\Search\Tests\Fixtures\BeginningsOnlyMatcher;
use Atrium\Search\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Words are found in every writing system: inside German compounds, in
 * Chinese and Japanese written without spaces, in Cyrillic in any case.
 */
final class LanguagesTest extends TestCase
{
    /**
     * @return array<string,array{string,string,string}>
     */
    public static function texts(): array
    {
        return [
            'German compound, its end' => ['Gartenschlauch mit Brause', 'schlauch', 'Garten<mark>schlauch</mark> mit Brause'],
            'German compound, its middle' => ['Wasserschlauchhalter', 'schlauch', 'Wasser<mark>schlauch</mark>halter'],
            'German umlaut' => ['Große Gießkanne', 'gießkanne', 'Große <mark>Gießkanne</mark>'],
            'Japanese without spaces' => ['緑茶用の急須ポット', 'ポット', '緑茶用の急須<mark>ポット</mark>'],
            'Japanese, two words' => ['緑茶用の急須ポット', '緑茶 急須', '<mark>緑茶</mark>用の<mark>急須</mark>ポット'],
            'Chinese without spaces' => ['绿茶紫砂茶壶', '茶壶', '绿茶紫砂<mark>茶壶</mark>'],
            'Cyrillic in capitals' => ['Электрический чайник', 'ЧАЙНИК', 'Электрический <mark>чайник</mark>'],
            'Ukrainian letters' => ['Їжак на ґанку', 'ґанку', 'Їжак на <mark>ґанку</mark>'],
            'Kazakh letters' => ['Қазақ шайнегі', 'шайнегі', 'Қазақ <mark>шайнегі</mark>'],
        ];
    }

    #[DataProvider('texts')]
    public function test_a_word_is_found_in_every_writing_system(string $name, string $query, string $expected): void
    {
        $this->product($name, '', ['code' => 'item']);
        $this->product('Desk lamp', '', ['code' => 'desk-lamp']);

        $this->get('/search?q='.urlencode($query))->assertOk()->assertSee($expected, false)->assertDontSee('Desk lamp');
    }

    public function test_words_inside_longer_ones_are_looked_for_only_when_whole_words_find_nothing(): void
    {
        $this->app->instance(Matcher::class, new BeginningsOnlyMatcher);
        $this->product('Schlauchboot', '', ['code' => 'boat']);
        $this->product('Gartenschlauch', '', ['code' => 'hose']);

        $this->get('/search?q=schlauch')->assertOk()->assertSee('<mark>Schlauch</mark>boot', false)->assertDontSee('Garten');
    }

    public function test_when_whole_words_find_nothing_the_inside_of_words_is_searched(): void
    {
        $this->app->instance(Matcher::class, new BeginningsOnlyMatcher);
        $this->product('Gartenschlauch', '', ['code' => 'hose']);
        $this->product('Desk lamp', '', ['code' => 'desk-lamp']);

        $this->get('/search?q=schlauch')->assertOk()->assertSee('Garten<mark>schlauch</mark>', false)->assertDontSee('Desk lamp');
        $this->get('/search?q=nothing')->assertOk()->assertSee('data-search-empty', false);
    }
}
