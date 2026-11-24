<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search;

use Atrium\Admin\Scopes\SiteScope;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\RecordUrls;
use Atrium\Search\Models\SearchEntry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the search index in step with the records: a saved record is
 * written anew, a deleted one taken out. The index keeps the activity and
 * the dates of a record, so scheduled publication needs no rebuild.
 */
final class Indexer
{
    private const MAX_TITLE = 500;

    /** Characters of text kept per record: enough for snippets and words. */
    private const MAX_CONTENT = 60000;

    public function __construct(
        private readonly Searchables $searchables,
        private readonly RecordUrls $urls,
        private readonly ContentLanguages $languages,
    ) {}

    public function index(Model $record): void
    {
        if (! $this->searchables->has($record)) {
            return;
        }

        DB::transaction(function () use ($record): void {
            $this->remove($record);

            foreach ($this->rows($record) as $row) {
                SearchEntry::query()->insert($row);
            }
        });
    }

    public function remove(Model $record): void
    {
        SearchEntry::query()
            ->where('searchable_type', $record::class)
            ->where('searchable_id', $record->getKey())
            ->delete();
    }

    /**
     * Index every record of every searchable model (or of one), across all
     * sites. Returns model => rows written.
     *
     * @param  (Closure(class-string<Model>,int):void)|null  $progress
     * @return array<class-string<Model>,int>
     */
    public function rebuild(?string $only = null, ?Closure $progress = null): array
    {
        $this->searchables->flush();
        $counts = [];

        foreach (array_keys($this->searchables->all()) as $class) {
            if ($only !== null && $class !== ltrim($only, '\\')) {
                continue;
            }

            SearchEntry::query()->where('searchable_type', $class)->delete();
            $counts[$class] = 0;

            $class::query()->withoutGlobalScope(SiteScope::class)->lazyById(200)->each(function (Model $record) use ($class, &$counts): void {
                foreach (array_chunk($this->rows($record), 50) as $rows) {
                    SearchEntry::query()->insert($rows);
                    $counts[$class] += count($rows);
                }
            });

            if ($progress !== null) {
                $progress($class, $counts[$class]);
            }
        }

        // Rows of models that are no longer searched.
        if ($only === null) {
            SearchEntry::query()->whereNotIn('searchable_type', array_keys($this->searchables->all()))->delete();
        }

        return $counts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(Model $record): array
    {
        $rows = [];
        $now = now();

        foreach ($this->searchables->documents($record) as $document) {
            $url = $this->urls->url($record, $document['language'] ?? $this->languages->default());

            // No page in this language: nothing to find.
            if ($url === null) {
                continue;
            }

            $title = mb_substr($document['title'], 0, self::MAX_TITLE);
            $content = mb_substr($document['content'], 0, self::MAX_CONTENT);

            $rows[] = [
                'searchable_type' => $record::class,
                'searchable_id' => $record->getKey(),
                'site_id' => $record->getAttributes()['site_id'] ?? null,
                'language' => $document['language'],
                'title' => $title,
                'content' => $content,
                'words' => mb_strtolower($title.' '.$content),
                'url' => self::path($url),
                'active' => (bool) ($record->getAttributes()['active'] ?? true),
                'active_from' => $record->getAttributes()['active_from'] ?? null,
                'active_to' => $record->getAttributes()['active_to'] ?? null,
                // The record's own dates: "newest first" among the results.
                'created_at' => $record->getAttributes()['created_at'] ?? $now,
                'updated_at' => $record->getAttributes()['updated_at'] ?? $now,
            ];
        }

        return $rows;
    }

    /**
     * The address without scheme and host: a record saved in the admin of
     * one domain is found on the site of another.
     */
    private static function path(string $url): string
    {
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return isset($parts['host']) ? $path : $url;
    }
}
