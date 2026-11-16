<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A model outside the admin that says itself what is found in it: nothing
 * in German, nothing when it has no title, and an error when it is broken.
 */
final class Leaflet extends Model
{
    protected $table = 'search_test_products';

    protected $guarded = [];

    /**
     * @return array{title:string,content:string}|null
     */
    public function toSearchDocument(string $language): ?array
    {
        if ($this->name === 'Broken') {
            throw new RuntimeException('Index down');
        }

        if ($language === 'de') {
            return null;
        }

        return ['title' => str_starts_with($this->name, 'Untitled') ? '<b> </b>' : $this->name.' leaflet', 'content' => (string) $this->description];
    }
}
