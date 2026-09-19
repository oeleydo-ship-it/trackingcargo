<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns what someone typed into a list search into the pieces a query needs.
 *
 * The term is split into words, each of which has to match somewhere, so
 * "acme manila" finds Acme's shipments to Manila without both words having to
 * sit in one column. The count is capped so a pasted paragraph cannot become a
 * query with dozens of joins.
 */
final class SearchWords
{
    private const int MAX_WORDS = 6;

    /** @return list<string> */
    public static function split(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /** A LIKE pattern matching the word anywhere, with % and _ taken literally. */
    public static function like(string $word): string
    {
        return '%'.addcslashes($word, '%_\\').'%';
    }
}
