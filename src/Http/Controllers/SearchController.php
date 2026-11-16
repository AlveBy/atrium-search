<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Http\Controllers;

use Atrium\Admin\Support\ContentLanguages;
use Atrium\Search\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * /search?q=…: what the visitor found, by module. Without a module chosen,
 * the first results of each with a link to all of them; with one (?in=news),
 * its results page by page.
 */
final class SearchController extends Controller
{
    private const MAX_QUERY = 200;

    public function __construct(
        private readonly Search $search,
        private readonly ContentLanguages $languages,
    ) {}

    public function __invoke(Request $request): View
    {
        $query = Str::limit(trim((string) $request->query('q', '')), self::MAX_QUERY, '');
        $in = $this->search->model((string) $request->query('in', ''));
        $counts = $this->search->counts($query);
        $groups = [];
        $results = null;

        if ($in !== null) {
            $results = $this->search->paginate($query, [$in])->withQueryString();
        } else {
            foreach ($counts as $model => $count) {
                $groups[] = [
                    'key' => $this->search->key($model),
                    'label' => $this->search->label($model),
                    'count' => $count,
                    'hits' => $this->search->top($query, $model, (int) config('atrium-search.group_limit')),
                ];
            }
        }

        $switch = [];

        foreach ($this->languages->codes() as $code) {
            $switch[$code] = $this->languages->route('atrium.search', array_filter(['q' => $query]), $code);
        }

        return view('atrium-search::results', [
            'query' => $query,
            'searched' => $this->search->accepts($query),
            'tooShort' => $query !== '' && ! $this->search->accepts($query),
            'total' => array_sum($counts),
            'filters' => array_map(fn (string $model, int $count): array => [
                'key' => $this->search->key($model),
                'label' => $this->search->label($model),
                'count' => $count,
                'url' => $this->url($query, $this->search->key($model)),
                'current' => $model === $in,
            ], array_keys($counts), $counts),
            'allUrl' => $this->url($query),
            'action' => $this->url(''),
            'in' => $in,
            'groups' => $groups,
            'results' => $results,
            'switch' => $switch,
        ]);
    }

    private function url(string $query, ?string $in = null): string
    {
        return $this->languages->route('atrium.search', array_filter(['q' => $query, 'in' => $in]));
    }
}
