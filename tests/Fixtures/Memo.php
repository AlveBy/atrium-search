<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Fixtures;

use Atrium\Admin\Columns\TextColumn;

/**
 * A module whose list has no searchable column: found by id only.
 */
final class Memo extends Product
{
    public static function getAdminLabel(): string
    {
        return 'Memos';
    }

    public static function getAdministrationListColumns(): array
    {
        return [TextColumn::make('name')->label('Name')];
    }
}
