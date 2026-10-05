<?php
/**
 * Shared author normalisation, and the merge that uses it.
 *
 * Two bugs were found while building this and are the reason the guards below are
 * written the way they are. Both were silent - no error, just wrong grouping:
 *
 *   1. `[^\p{L}\p{N}]` excludes combining marks. Gurmukhi vowel signs (ੁ ੇ ਿ ੀ) are
 *      category Mn, not L, so stripping "non letters" deleted every vowel sign and
 *      shattered ਗੁਰਸੇਵਕ ਸਿੰਘ ਧੌਲਾ into loose single characters. Fixed by allowing \p{M}.
 *
 *   2. A character class without the /u modifier matches bytes, not characters. An
 *      en-dash inside UTF-8 then matched mid-character and corrupted the string, which
 *      made the *next* /u regex return NULL - and NULL silently keyed to "". Every
 *      such author collapsed into one bogus empty-key group.
 *
 * So: /u on every pattern, and \p{M} preserved. Both are load-bearing.
 */
function authorKey(string $a): string
{
    $s = mb_strtolower(trim($a));

    // Archive's "Name - Romanised Name" is the same person, not two.
    $s = preg_replace('#\s*[-–—|]\s*#u', ' ', (string) $s);

    // Titles and roles, which are not part of identity: Dr., Prof., (Dr.), ਡਾ.
    $s = preg_replace('/[\(\[\{].*?[\)\]\}]/u', ' ', (string) $s);
    $s = preg_replace('/\b(dr|prof|mr|mrs|ms|sardar|bhai|baba|sant|giani|ਡਾ|ਡਾਃ|ਪ੍ਰੋ|ਸਰਦਾਰ|ਭਾਈ|ਬਾਬਾ|ਸੰਤ|ਗਿਆਨੀ)\b/u', ' ', (string) $s);

    // \p{M} is essential - see bug 1 above.
    $s = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', (string) $s);

    return trim(preg_replace('/\s+/u', ' ', (string) $s));
}

/** Values that are not a person and must never get an author page. */
function isNonPersonAuthor(string $a): bool
{
    $t = mb_strtolower(trim($a));
    if ($t === '' || $t === 'unknown') {
        return true;
    }
    return (bool) preg_match('#(\.com|\.org|\.net|https?://|www\.|library|archive|scanning|digitiz)#u', $t);
}

/**
 * Titles that sit around or inside a name and carry no identity.
 * Kept deliberately small: stripping too much merges genuinely different people,
 * which is far worse than leaving two near-duplicate author rows unmerged.
 */
function authorTokens(string $a): array
{
    $s = mb_strtolower(trim($a));
    $s = preg_replace('#\s*[-–—|]\s*#u', ' ', (string) $s);
    $s = preg_replace('/[\(\[\{].*?[\)\]\}]/u', ' ', (string) $s);
    $s = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', (string) $s);
    $parts = preg_split('/\s+/u', trim((string) $s)) ?: [];
    return array_values(array_filter($parts, static fn($w) => !in_array($w, [
        'dr', 'prof', 'mr', 'mrs', 'ms', 'sardar', 'bhai', 'baba', 'sant',
        '\u0a21\u0a3e', '\u0a2a\u0a4d\u0a30\u0a4b', '\u0a38\u0a30\u0a26\u0a3e\u0a30',
        '\u0a2d\u0a3e\u0a08', '\u0a2c\u0a3e\u0a2c\u0a3e', '\u0a38\u0a70\u0a24',
    ], true)));
}

/**
 * Group author values that are the same person.
 *
 * Split into two buckets on purpose:
 *   safe   - identical token sets, differing only in punctuation, spacing or a title.
 *            "ਸੁਖਵੰਤ ਹੁੰਦਲ - Sukhwant Hundal" vs "ਸੁਖਵੰਤ ਹੁੰਦਲ-Sukhwant Hundal".
 *            Unambiguous, safe to merge.
 *   unsafe - one name is a subset of another, e.g. "Dr. Kirpal Singh" inside
 *            "Kirpal Singh Giani". Those may be two different people. Reported,
 *            never merged automatically.
 *
 * @return array{safe: array, unsafe: array}
 */
function authorGroups(PDO $pdo): array
{
    $byKey = [];
    foreach ($pdo->query('SELECT Author, COUNT(*) n FROM products GROUP BY Author') as $r) {
        $a = (string) $r['Author'];
        if (isNonPersonAuthor($a)) {
            continue;
        }
        $tok = authorTokens($a);
        if (!$tok) {
            continue;
        }
        sort($tok);
        $byKey[implode(' ', $tok)][] = [$a, (int) $r['n']];
    }

    $safe = [];
    foreach ($byKey as $key => $g) {
        if (count($g) > 1) {
            $safe[$key] = $g;
        }
    }

    // Unsafe: same surname plus one shared given token, but not identical sets.
    $unsafe = [];
    foreach ($byKey as $g) {
        foreach ($g as [$a, $n]) {
            $ta = authorTokens($a);
            if (!$ta) {
                continue;
            }
            foreach ($byKey as $key2 => $g2) {
                if ($key2 === implode(' ', $ta) || count($g2) < 2) {
                    continue;
                }
                $tb = authorTokens($g2[0][0]);
                $shared = count(array_intersect($ta, $tb));
                if ($shared >= 2 && $shared < min(count($ta), count($tb))) {
                    $unsafe[implode(' ', $ta) . ' ~ ' . $key2] = [[$a, $n], $g2];
                }
            }
        }
    }

    return ['safe' => $safe, 'unsafe' => $unsafe];
}
