<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search;

use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Support\RecordUrls;
use Atrium\Admin\Support\Sites;
use Atrium\Search\Models\SearchEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The site search over the index: the records of the current site in the
 * current language that are active today. Matcher says how words are
 * found.
 */
final class Search
{
    private const MAX_TERMS = 8;

    private const SNIPPET = 220;

    public function __construct(
        private readonly Searchables $searchables,
        private readonly RecordUrls $urls,
        private readonly Sites $sites,
        private readonly Matcher $matcher,
    ) {}

    /**
     * The words of a query, in lower case, without punctuation.
     *
     * @return array<int,string>
     */
    public function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_unique($words), 0, self::MAX_TERMS);
    }

    /**
     * Whether the query is long enough to search.
     */
    public function accepts(string $query): bool
    {
        return $this->terms($query) !== [] && mb_strlen(trim($query)) >= config('atrium-search.min_length');
    }

    /**
     * The key of a model in addresses (?in=news) and answers.
     */
    public function key(string $model): string
    {
        return is_subclass_of($model, Administrable::class) ? $model::getAdminRoutePrefix() : Str::snake(Str::pluralStudly(class_basename($model)));
    }

    /**
     * @return class-string<Model>|null
     */
    public function model(string $key): ?string
    {
        foreach (array_keys($this->searchables->all()) as $model) {
            if ($this->key($model) === $key) {
                return $model;
            }
        }

        return null;
    }

    public function label(string $model): string
    {
        return method_exists($model, 'getAdminLabel') ? $model::getAdminLabel() : Str::headline(class_basename($model));
    }

    /**
     * Found records per model, in the order of the modules.
     *
     * @param  array<int,string>|null  $models  only these
     * @return array<class-string<Model>,int>
     */
    public function counts(string $query, ?array $models = null): array
    {
        if (! $this->accepts($query)) {
            return [];
        }

        $found = $this->matching($query, $models)
            ->toBase()
            ->select('searchable_type')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('searchable_type')
            ->pluck('aggregate', 'searchable_type')
            ->all();

        $counts = [];

        foreach (array_keys($this->searchables->all()) as $model) {
            if (isset($found[$model])) {
                $counts[$model] = $found[$model];
            }
        }

        return $counts;
    }

    /**
     * A page of results.
     *
     * @param  array<int,string>|null  $models  only these
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function paginate(string $query, ?array $models = null, ?int $perPage = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage ??= config('atrium-search.per_page');
        $paginator = $this->accepts($query)
            ? $this->ordered($query, $models)->paginate($perPage, ['*'], $pageName)
            : SearchEntry::query()->whereRaw('1 = 0')->paginate($perPage, pageName: $pageName);

        $hits = $this->hits($paginator->getCollection(), $this->terms($query));

        return $paginator->setCollection(collect($hits));
    }

    /**
     * The first results of one model.
     *
     * @return array<int,array<string,mixed>>
     */
    public function top(string $query, string $model, int $limit): array
    {
        if (! $this->accepts($query)) {
            return [];
        }

        return $this->hits($this->ordered($query, [$model])->limit($limit)->get(), $this->terms($query));
    }

    /**
     * @param  array<int,string>|null  $models
     * @return Builder<SearchEntry>
     */
    private function matching(string $query, ?array $models): Builder
    {
        $searchable = array_keys($this->searchables->all());
        $models = $models === null ? $searchable : array_intersect($models, $searchable);
        $language = app()->getLocale();

        $builder = SearchEntry::query()
            ->whereIn('searchable_type', $models)
            ->where(fn (Builder $q) => $q->where('site_id', $this->sites->current()->id)->orWhereNull('site_id'))
            ->where(fn (Builder $q) => $q->where('language', $language)->orWhereNull('language'))
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('active_from')->orWhere('active_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('active_to')->orWhere('active_to', '>=', now()));

        $terms = $this->terms($query);
        $whole = clone $builder;

        if (! $this->matcher->apply($whole, $terms) || $whole->exists()) {
            return $whole;
        }

        // Nothing by whole words and their beginnings: the words may sit
        // inside longer ones, as in German compounds.
        $this->matcher->apply($builder, $terms, inside: true);

        return $builder;
    }

    /**
     * @param  array<int,string>|null  $models
     * @return Builder<SearchEntry>
     */
    private function ordered(string $query, ?array $models): Builder
    {
        $first = $this->terms($query)[0] ?? '';

        // Found in the title first, then the newest.
        return $this->matching($query, $models)
            ->orderByRaw("case when lower(title) like ? escape '!' then 0 else 1 end", ['%'.Matcher::escapeLike($first).'%'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /**
     * Index rows as results: the live address of the record (the index keeps
     * the one it had when saved), the title and a snippet with the words
     * marked.
     *
     * @param  Collection<int,SearchEntry>  $entries
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function hits(Collection $entries, array $terms): array
    {
        $records = [];

        foreach ($entries->groupBy('searchable_type') as $model => $rows) {
            if (is_subclass_of($model, Model::class)) {
                $records[$model] = $model::query()->findMany($rows->pluck('searchable_id')->all())->getDictionary();
            }
        }

        $hits = [];

        foreach ($entries as $entry) {
            $record = $records[$entry->searchable_type][$entry->searchable_id] ?? null;
            $url = $record instanceof Model ? $this->urls->url($record) : null;

            $hits[] = [
                'module' => $this->key($entry->searchable_type),
                'model' => $entry->searchable_type,
                'id' => $entry->searchable_id,
                'label' => $this->label($entry->searchable_type),
                'title' => $entry->title,
                'url' => $url ?? url($entry->url),
                'snippet' => self::snippet($entry->content, $terms),
                'title_html' => self::highlight($entry->title, $terms),
            ];
        }

        return $hits;
    }

    /**
     * A piece of the text around the first word found, with the words
     * marked.
     *
     * @param  array<int,string>  $terms
     */
    public static function snippet(string $text, array $terms): HtmlString
    {
        $start = 0;

        foreach ($terms as $term) {
            $position = mb_stripos($text, $term);

            if ($position !== false) {
                $start = max(0, $position - (int) (self::SNIPPET / 3));
                break;
            }
        }

        $piece = mb_substr($text, $start, self::SNIPPET);

        // Whole words at the edges.
        if ($start > 0) {
            $piece = '…'.ltrim((string) preg_replace('/^\S*\s/u', '', $piece));
        }

        if ($start + self::SNIPPET < mb_strlen($text)) {
            $piece = rtrim((string) preg_replace('/\s\S*$/u', '', $piece)).'…';
        }

        return self::highlight($piece, $terms);
    }

    /**
     * The text, escaped, with every word of the query in <mark>.
     *
     * @param  array<int,string>  $terms
     */
    public static function highlight(string $text, array $terms): HtmlString
    {
        $terms = array_filter($terms, static fn (string $term): bool => $term !== '');

        if ($terms === []) {
            return new HtmlString(e($text));
        }

        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/('.implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)).')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';

        foreach ($parts as $index => $part) {
            $html .= $index % 2 === 1 ? '<mark>'.e($part).'</mark>' : e($part);
        }

        return new HtmlString($html);
    }
}
