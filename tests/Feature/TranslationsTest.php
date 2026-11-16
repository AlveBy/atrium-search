<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Search\Tests\Feature;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Every language has every line English has, no other, none empty, and
 * the same placeholders.
 */
final class TranslationsTest extends TestCase
{
    public function test_every_language_has_the_english_lines_and_placeholders(): void
    {
        $dir = dirname(__DIR__, 2).'/lang';
        $english = Arr::dot(require $dir.'/en/search.php');
        $languages = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));

        $this->assertEqualsCanonicalizing(['be', 'de', 'en', 'es', 'fr', 'it', 'ja', 'kk', 'pl', 'pt_BR', 'ru', 'tr', 'uk', 'uz', 'zh_CN'], $languages);

        foreach ($languages as $language) {
            $lines = Arr::dot(require $dir.'/'.$language.'/search.php');

            $this->assertSame(array_keys($english), array_keys($lines), $language);

            foreach ($lines as $key => $line) {
                $this->assertNotSame('', trim((string) $line), "{$language}: {$key}");
                $this->assertSame(self::placeholders((string) $english[$key]), self::placeholders((string) $line), "{$language}: {$key}");
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private static function placeholders(string $line): array
    {
        preg_match_all('/:[a-z_]+/', $line, $found);
        $names = array_unique($found[0]);
        sort($names);

        return $names;
    }
}
