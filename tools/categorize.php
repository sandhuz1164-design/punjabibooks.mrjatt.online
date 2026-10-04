<?php
/**
 * Shared categorisation rules for Archive.org titles.
 *
 * This lives in its own file so tools/import.php and tools/recategorize.php
 * cannot drift apart. They already did once: recategorize.php had a hand-written
 * plain_title() that cut at the first "(" instead of at a "- PDF" suffix, which
 * stripped the Latin "Tarksheel" out of the matching blob and proposed moving 142
 * periodical issues out of the Magazines bucket. One copy, loaded by both.
 */

const PERIODICAL_HINTS = [
    'ਸਮਤਾ', 'ਸੇਧ', 'ਵਤਨ', 'ਵਤਨੋਂ', 'ਸਿਰਜਣਾ', 'ਤਰਕਸ਼ੀਲ', 'ਮੰਚਣ', 'ਦਰਪਣ', 'ਪ੍ਰਚਾਪੀ', 'ਲੋਕ-ਰਾਜ਼',
    'samta', 'sedh', 'vatan', 'sirjana', 'tarksheel', 'manchan', 'darpan', 'prachapi', 'watan',
    'sukhwant', 'sukhwan',
];

const JUNK_HINTS = ['angreji123456', 'screenshot-', 'types de cancer', 'um constant', 'punjab_assembly_election'];

function strip_html($s): string
{
    $s = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $s);
    $s = preg_replace('#<br\s*/?>#i', ' ', $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Archive.org titles are frequently a list, and often carry a trailing
 * " - PDF" / "(sample)" artefact. Keep the title itself, drop the artefact -
 * deliberately *not* everything after the first bracket, since bracketed issue
 * numbers ("ਤਰਕਸ਼ੀਲ (1) ਮਈ-ਜੂਨ 1986, Tarksheel, ...") are load-bearing for
 * matching the Latin hint "tarksheel".
 */
function plain_title($t): string
{
    $t = strip_html(is_array($t) ? (string) reset($t) : $t);
    $t = preg_replace('/\s*[-\(]\s*(Free\s+)?(PDF|sample|Wps Word).*$/iu', '', (string) $t);
    return trim((string) $t);
}

/**
 * Archive.org returns creator and subject as a string, a list of strings, or a
 * list of lists. Casting an array to string yields the literal "Array" and
 * implode() on a list of lists drops every inner element, which made categorize()
 * blind to those fields. Flatten first.
 */
function flatten_field($v): string
{
    if (is_array($v)) {
        return implode(' ', array_map('flatten_field', $v));
    }
    return is_scalar($v) ? (string) $v : '';
}

function categorize(array $m, string $title): string
{
    $blob = mb_strtolower($title . ' ' . flatten_field($m['subject'] ?? '') . ' ' . flatten_field($m['creator'] ?? ''));

    foreach (JUNK_HINTS as $j) {
        if (str_contains($blob, $j)) {
            return 'Skipped';
        }
    }
    foreach (PERIODICAL_HINTS as $p) {
        if (str_contains($blob, $p)) {
            return 'Magazines';
        }
    }
    $rules = [
        'Sikh Literature' => ['sikhism', 'sikhi', 'sikh', 'granth', 'ਸਿੱਖ', 'ਗ੍ਰੰਥ', 'ਭਾਗ ਸਿੱਖੀ'],
        'Poetry'          => ['kavita', 'poetry', 'ਕਵਿਤਾ', 'ਕੁਹਾਣੀ', 'ਗੀਤ'],
        'Fiction'         => ['novel', 'kahani', 'ਕਹਾਣੀ', 'ਦਾਪਾਨ', 'ਉਪਨਿਯਾਸ'],
        'Grammar'         => ['grammar', 'vyakaran', 'ਵਿਆਕਰਨ', 'ਲੁਗਤ'],
        'Dictionary'      => ['dictionary', 'lughat', 'ਲੁਘਤ', 'kosh'],
        'History'         => ['history', 'itihas', 'ਇਤਿਹਾਸ', 'ਤਰੀਖ'],
        'Punjabi Literature' => ['ਪੰਜਾਬੀ ਸਾਹਿਤ', 'punjabi literature', 'punjabi adab', 'ਪੰਜਾਬੀ ਅਤੇ ਹਿੰਦੀ'],
    ];
    foreach ($rules as $cat => $hints) {
        foreach ($hints as $h) {
            if (str_contains($blob, $h)) {
                return $cat;
            }
        }
    }
    return 'Punjabi Books';
}
