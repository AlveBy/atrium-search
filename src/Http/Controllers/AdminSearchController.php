<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Http\Controllers;

use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Resources\ResourceRegistry;
use Atrium\Admin\Support\RecordSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The editors' box in the admin header: records of every module the
 * administrator may see, found the way each module's own list finds them,
 * inactive and unpublished ones too, with a link to the form.
 */
final class AdminSearchController extends Controller
{
    private const PER_MODULE = 5;

    public function __invoke(Request $request, ResourceRegistry $resources, RecordSearch $search): JsonResponse
    {
        $text = trim((string) $request->query('q', ''));
        $user = $request->user(config('atrium.auth.guard', 'admin'));
        $groups = [];

        if (mb_strlen($text) < 2) {
            return response()->json(['groups' => []]);
        }

        foreach ($resources->all() as $key => $model) {
            if (! Route::has("admin.{$key}.edit") || ! $search->mayView($user instanceof AdminUser ? $user : null, $model)) {
                continue;
            }

            if (! ctype_digit($text) && $search->searchableColumns($model) === []) {
                continue;
            }

            try {
                $records = $search->query($model, $text)->limit(self::PER_MODULE)->get();
            } catch (Throwable $e) {
                // One module's broken list must not take the box down.
                report($e);

                continue;
            }

            if ($records->isEmpty()) {
                continue;
            }

            $groups[] = [
                'module' => $key,
                'label' => $model::getAdminLabel(),
                'items' => $records->map(fn (Model $record): array => [
                    'id' => $record->getKey(),
                    'label' => $search->label($record),
                    'url' => route("admin.{$key}.edit", ['id' => $record->getKey()]),
                    'hint' => '#'.$record->getKey(),
                ])->values()->all(),
            ];
        }

        return response()->json(['groups' => $groups]);
    }
}
