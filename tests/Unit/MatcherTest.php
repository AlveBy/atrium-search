<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Unit;

use Atrium\Search\Matcher;
use Atrium\Search\Models\SearchEntry;
use Atrium\Search\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The SQL each database gets for the words of a query. The MySQL, MariaDB
 * and PostgreSQL connections here are never opened: only the query is
 * built.
 */
final class MatcherTest extends TestCase
{
    private const LIKE = "words like ? escape '!'";

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
            $app['config']->set("database.connections.unopened_{$driver}", [
                'driver' => $driver, 'host' => '192.0.2.1', 'database' => 'none', 'username' => 'none', 'password' => '', 'prefix' => '',
            ]);
        }
    }

    /**
     * Each condition with its value.
     *
     * @return array<int,array{0:string,1:array<int,mixed>}>
     */
    private function wheres(Builder $builder): array
    {
        $query = $builder->getQuery();
        $this->assertCount(count($query->wheres), $query->bindings['where']);

        return array_map(static fn (array $where, mixed $binding): array => [$where['sql'], [$binding]], $query->wheres, $query->bindings['where']);
    }

    private function builder(string $driver): Builder
    {
        return SearchEntry::on("unopened_{$driver}");
    }

    public function test_mysql_matches_long_words_and_their_beginnings_with_the_full_text_index(): void
    {
        $builder = $this->builder('mysql');

        $this->assertTrue((new Matcher)->apply($builder, ['garden', 'hose']));
        $this->assertSame([['match(words) against (? in boolean mode)', ['+garden* +hose*']]], $this->wheres($builder));
    }

    public function test_mariadb_is_matched_like_mysql(): void
    {
        $builder = $this->builder('mariadb');

        $this->assertTrue((new Matcher)->apply($builder, ['garden']));
        $this->assertSame([['match(words) against (? in boolean mode)', ['+garden*']]], $this->wheres($builder));
    }

    public function test_postgresql_matches_long_words_and_their_beginnings_with_its_text_search(): void
    {
        $builder = $this->builder('pgsql');

        $this->assertTrue((new Matcher)->apply($builder, ['garden', 'hose']));
        $this->assertSame([["to_tsvector('simple', words) @@ to_tsquery('simple', ?)", ['garden:* & hose:*']]], $this->wheres($builder));
    }

    public function test_sqlite_finds_every_word_anywhere(): void
    {
        $builder = SearchEntry::on('testing');

        $this->assertFalse((new Matcher)->apply($builder, ['garden', 'hose']));
        $this->assertSame([[self::LIKE, ['%garden%']], [self::LIKE, ['%hose%']]], $this->wheres($builder));
    }

    /**
     * @return array<string,array{string,bool}>
     */
    public static function words(): array
    {
        return [
            'three letters' => ['tea', true],
            'two letters' => ['tv', false],
            'one letter' => ['x', false],
            'a MySQL stopword' => ['the', false],
            'a German stopword' => ['und', false],
            'Cyrillic' => ['чайник', true],
            'two Cyrillic letters' => ['ты', false],
            'three Cyrillic letters' => ['чай', true],
            'Chinese' => ['绿茶叶', false],
            'Japanese hiragana' => ['おちゃ', false],
            'Japanese katakana' => ['ポット', false],
            'Korean' => ['녹차잎', false],
            'Thai' => ['ชาเขียว', false],
        ];
    }

    #[DataProvider('words')]
    public function test_mysql_leaves_short_words_stopwords_and_scripts_without_spaces_to_like(string $word, bool $indexed): void
    {
        $builder = $this->builder('mysql');

        $this->assertSame($indexed, (new Matcher)->apply($builder, [$word]));
        $this->assertSame(
            $indexed ? [['match(words) against (? in boolean mode)', ['+'.$word.'*']]] : [[self::LIKE, ['%'.$word.'%']]],
            $this->wheres($builder),
        );
    }

    public function test_postgresql_has_no_stopwords_but_leaves_short_words_and_chinese_to_like(): void
    {
        $builder = $this->builder('pgsql');

        $this->assertTrue((new Matcher)->apply($builder, ['the', 'tv', '绿茶叶']));
        $this->assertSame([
            [self::LIKE, ['%tv%']],
            [self::LIKE, ['%绿茶叶%']],
            ["to_tsvector('simple', words) @@ to_tsquery('simple', ?)", ['the:*']],
        ], $this->wheres($builder));
    }

    public function test_only_short_words_leave_the_full_text_index_out(): void
    {
        $builder = $this->builder('mysql');

        $this->assertFalse((new Matcher)->apply($builder, ['tv', 'of']));
        $this->assertSame([[self::LIKE, ['%tv%']], [self::LIKE, ['%of%']]], $this->wheres($builder));
    }

    public function test_inside_finds_every_word_anywhere_on_every_database(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            $builder = $this->builder($driver);

            $this->assertFalse((new Matcher)->apply($builder, ['schlauch', 'tv'], inside: true), $driver);
            $this->assertSame([[self::LIKE, ['%schlauch%']], [self::LIKE, ['%tv%']]], $this->wheres($builder), $driver);
        }
    }

    public function test_like_wildcards_and_the_escape_character_are_searched_as_they_are(): void
    {
        $this->assertSame('50!%!_off!!', Matcher::escapeLike('50%_off!'));
        $this->assertSame('plain', Matcher::escapeLike('plain'));
    }
}
