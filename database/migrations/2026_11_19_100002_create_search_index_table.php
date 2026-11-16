<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per record and language: what the site search looks in. Filled on
 * save, rebuilt by atrium:search:rebuild. MySQL and PostgreSQL get a
 * full-text index on the words; SQLite searches with LIKE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table): void {
            $table->id();
            $table->string('searchable_type');
            $table->unsignedBigInteger('searchable_id');
            $table->foreignId('site_id')->nullable();
            // Null: the record is not translated, it is found in every language.
            $table->string('language', 16)->nullable();
            $table->string('title', 500);
            $table->mediumText('content');
            // Title and text in lower case: what the search compares with.
            $table->mediumText('words');
            $table->string('url', 2048);
            $table->boolean('active')->default(true);
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_to')->nullable();
            $table->timestamps();

            $table->index(['searchable_type', 'searchable_id']);
            $table->index(['site_id', 'language']);

            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $table->fullText('words');
            } elseif ($driver === 'pgsql') {
                $table->fullText('words')->language('simple');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index');
    }
};
