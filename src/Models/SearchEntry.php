<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record of some module as the search sees it, in one language.
 *
 * @property int $id
 * @property string $searchable_type
 * @property int $searchable_id
 * @property int|null $site_id
 * @property string|null $language
 * @property string $title
 * @property string $content
 * @property string $words
 * @property string $url
 * @property bool $active
 */
class SearchEntry extends Model
{
    protected $table = 'search_index';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'searchable_id' => 'integer',
            'site_id' => 'integer',
            'active' => 'boolean',
            'active_from' => 'datetime',
            'active_to' => 'datetime',
        ];
    }
}
