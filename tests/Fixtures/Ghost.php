<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * A module whose list fails. It fails before asking the database: on
 * PostgreSQL a failed query would also break the test's transaction, and
 * with it the modules searched after this one.
 */
final class Ghost extends Product
{
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAdminList(Builder $query): Builder
    {
        throw new RuntimeException('The list is broken.');
    }

    public static function getAdminLabel(): string
    {
        return 'Ghosts';
    }
}
