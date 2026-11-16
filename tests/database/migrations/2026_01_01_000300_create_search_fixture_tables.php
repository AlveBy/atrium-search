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
 * Translated news of a site and products shared by all sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_test_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable();
            $table->json('name');
            $table->json('code');
            $table->json('preview_text')->nullable();
            $table->json('detail_text')->nullable();
            $table->boolean('active')->default(true);
            $table->dateTime('active_from')->nullable();
            $table->dateTime('active_to')->nullable();
            $table->integer('sort')->default(500);
            $table->timestamps();
        });

        Schema::create('search_test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_test_products');
        Schema::dropIfExists('search_test_posts');
    }
};
