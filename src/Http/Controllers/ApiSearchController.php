<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Http\Controllers;

use Atrium\Admin\Api\ApiResources;
use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Search\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/site-search?q=…&module=news — what a visitor of the site
 * would find, for programs and AI assistants (the MCP tool search_site).
 * Only the modules the token may read.
 */
final class ApiSearchController extends Controller
{
    public function __invoke(Request $request, Search $search, ApiResources $resources, ContentLanguages $languages): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'module' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'locale' => ['nullable', 'string', Rule::in($languages->codes())],
        ]);

        // What a visitor of that language finds; the default language without it.
        app()->setLocale($data['locale'] ?? $languages->default());

        $owner = $request->user();
        $perPage = (int) ($data['per_page'] ?? config('atrium-search.per_page'));
        $counts = $search->counts($data['q']);
        $models = [];

        foreach (array_keys($counts) as $model) {
            $key = $resources->keyOf($model);

            if ($key !== null && $owner instanceof AdminUser && $owner->tokenCan("{$key}:read") && $owner->hasAdminPermission("{$key}.view")) {
                $models[$search->key($model)] = $model;
            }
        }

        if (isset($data['module'])) {
            $models = Arr::only($models, $data['module']);
        }

        $page = $models === []
            ? null
            : $search->paginate($data['q'], $models, $perPage);

        return response()->json([
            'data' => $page === null ? [] : array_map(static fn (array $hit): array => [
                'module' => $hit['module'],
                'id' => $hit['id'],
                'title' => $hit['title'],
                'url' => $hit['url'],
                'snippet' => strip_tags((string) $hit['snippet']),
            ], $page->items()),
            'counts' => array_map(static fn (string $model): int => $counts[$model], $models),
            'meta' => [
                'page' => $page?->currentPage() ?? 1,
                'per_page' => $perPage,
                'total' => $page?->total() ?? 0,
                'last_page' => $page?->lastPage() ?? 1,
            ],
        ]);
    }
}
