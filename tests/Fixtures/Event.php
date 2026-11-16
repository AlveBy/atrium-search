<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Atrium\Admin\Contracts\Sitemapable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model outside the admin that lists its pages itself: the address is
 * whatever its `url` column says, and activity is a number, as in old
 * databases.
 */
final class Event extends Model implements Sitemapable
{
    public $timestamps = false;

    protected $table = 'search_test_events';

    protected $guarded = [];

    public static function sitemapQuery(): Builder
    {
        return self::query();
    }

    public function sitemapUrl(string $language): ?string
    {
        return $this->url;
    }
}
