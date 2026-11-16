<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Atrium\Search\Matcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * A full-text index on SQLite, for the tests: it finds words and their
 * beginnings, never the inside of a word.
 */
final class BeginningsOnlyMatcher extends Matcher
{
    public function apply(Builder $builder, array $terms, bool $inside = false): bool
    {
        if ($inside) {
            return parent::apply($builder, $terms, true);
        }

        foreach ($terms as $term) {
            $builder->where(fn (Builder $q) => $q->where('words', 'like', $term.'%')->orWhere('words', 'like', '% '.$term.'%'));
        }

        return true;
    }
}
