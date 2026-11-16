<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\ContentField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Admin\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Translated news of one site, with dates of publication.
 */
class Post extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin, Translatable;

    protected $table = 'search_test_posts';

    protected $guarded = [];

    /** @var array<int,string> */
    protected array $translatable = ['name', 'code', 'preview_text', 'detail_text'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'active_from' => 'datetime', 'active_to' => 'datetime'];
    }

    public static function getAdminLabel(): string
    {
        return 'News';
    }

    public static function getAdministrationFields(): array
    {
        return [
            TextField::make('name')->label('Name'),
            TextareaField::make('preview_text')->label('Preview'),
            ContentField::make('detail_text')->label('Text'),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [TextColumn::make('name')->label('Name')->searchable()];
    }
}
