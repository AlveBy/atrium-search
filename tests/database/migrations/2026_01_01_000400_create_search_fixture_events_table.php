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
 * Events of an older database: long texts, activity as a number, no
 * timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_test_events', function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->string('url', 2048)->nullable();
            $table->mediumText('description')->nullable();
            $table->integer('active')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_test_events');
    }
};
