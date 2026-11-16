<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * The words of a query as conditions on the index: every word must be found.
 *
 * MySQL and PostgreSQL match words of three letters and more with their
 * full-text index, beginnings too ("phon" finds "phones"); shorter words,
 * MySQL's stopwords, Chinese and Japanese, and SQLite use LIKE.
 */
class Matcher
{
    /** Words MySQL's full-text index leaves out (INNODB_FT_DEFAULT_STOPWORD). */
    private const MYSQL_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is',
        'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will',
        'with', 'und', 'www',
    ];

    /**
     * Whether the full-text index was used. It sees whole words and their
     * beginnings only, so a caller that found nothing asks again with
     * $inside: every word anywhere, "schlauch" inside "Gartenschlauch".
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder
     * @param  array<int,string>  $terms
     */
    public function apply(Builder $builder, array $terms, bool $inside = false): bool
    {
        $driver = $builder->getModel()->getConnection()->getDriverName();
        $fullText = [];

        foreach ($terms as $term) {
            $indexed = ! $inside && mb_strlen($term) >= 3 && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}]/u', $term) !== 1;

            if ($indexed && in_array($driver, ['mysql', 'mariadb'], true) && ! in_array($term, self::MYSQL_STOPWORDS, true)) {
                $fullText[] = '+'.$term.'*';
            } elseif ($indexed && $driver === 'pgsql') {
                $fullText[] = $term.':*';
            } else {
                $builder->whereRaw("words like ? escape '!'", ['%'.self::escapeLike($term).'%']);
            }
        }

        if ($fullText === []) {
            return false;
        }

        if ($driver === 'pgsql') {
            $builder->whereRaw("to_tsvector('simple', words) @@ to_tsquery('simple', ?)", [implode(' & ', $fullText)]);
        } else {
            $builder->whereRaw('match(words) against (? in boolean mode)', [implode(' ', $fullText)]);
        }

        return true;
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
