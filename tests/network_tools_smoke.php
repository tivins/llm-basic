<?php

declare(strict_types=1);

/**
 * Network smoke tests for FetchWebPageTool, WebSearchTool, LangSearchTool, and OpenMeteoTool.
 *
 * Run:   php tests/network_tools_smoke.php
 *
 * LangSearch tests are skipped unless LANGSEARCH_API_KEY is set in the environment.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tivins\LlmBasic\Tools\FetchWebPageTool;
use Tivins\LlmBasic\Tools\LangSearchTool;
use Tivins\LlmBasic\Tools\OpenMeteoTool;
use Tivins\LlmBasic\Tools\WebSearchTool;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    } else {
        echo "OK:   {$message}\n";
    }
}

/** @param callable(): string $fn */
function assertJsonHasKey(callable $fn, string $key, string $message): mixed
{
    $raw  = $fn();
    $data = json_decode($raw, true);
    assertTrue(is_array($data), "{$message} (valid JSON)");
    assertTrue(array_key_exists($key, $data ?? []), "{$message} (key '{$key}' present)");
    return $data[$key] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────
// FetchWebPageTool
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== FetchWebPageTool ===\n";

$fetch = new FetchWebPageTool();

// Happy path: known stable URL, plain text extraction
$result = json_decode(($fetch->handler)(json_encode(['url' => 'https://example.com'])), true);
assertTrue(is_array($result), 'fetch example.com: valid JSON');
assertTrue(($result['http_status'] ?? 0) === 200, 'fetch example.com: HTTP 200');
assertTrue(isset($result['body']) && strlen($result['body']) > 0, 'fetch example.com: non-empty body');
assertTrue(($result['text_extracted'] ?? false) === true, 'fetch example.com: text extracted');
assertTrue(($result['truncated'] ?? true) === false, 'fetch example.com: not truncated');

// raw_html mode
$resultRaw = json_decode(($fetch->handler)(json_encode(['url' => 'https://example.com', 'raw_html' => true])), true);
assertTrue(is_array($resultRaw), 'fetch example.com raw_html: valid JSON');
assertTrue(($resultRaw['text_extracted'] ?? true) === false, 'fetch example.com raw_html: text_extracted false');
assertTrue(str_contains($resultRaw['body'] ?? '', '<html'), 'fetch example.com raw_html: body contains <html');

// max_bytes truncation (force 512 bytes)
$resultTrunc = json_decode(($fetch->handler)(json_encode(['url' => 'https://example.com', 'max_bytes' => 512])), true);
assertTrue(is_array($resultTrunc), 'fetch example.com max_bytes: valid JSON');

// Error: invalid URL
$errInvalid = json_decode(($fetch->handler)(json_encode(['url' => 'ftp://example.com'])), true);
assertTrue(isset($errInvalid['error']), 'fetch invalid scheme: error key present');

// Error: empty url
$errEmpty = json_decode(($fetch->handler)(json_encode(['url' => ''])), true);
assertTrue(isset($errEmpty['error']), 'fetch empty url: error key present');

// Error: bad url format
$errBad = json_decode(($fetch->handler)(json_encode(['url' => 'not a url'])), true);
assertTrue(isset($errBad['error']), 'fetch bad url format: error key present');

// ─────────────────────────────────────────────────────────────────────────────
// WebSearchTool
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== WebSearchTool ===\n";

$search = new WebSearchTool();

// Happy path
$searchResult = json_decode(($search->handler)(json_encode(['query' => 'PHP DOMDocument tutorial'])), true);
assertTrue(is_array($searchResult), 'web_search: valid JSON');
assertTrue(($searchResult['provider'] ?? '') === 'duckduckgo', 'web_search: provider = duckduckgo');
assertTrue(is_array($searchResult['results'] ?? null), 'web_search: results is array');
assertTrue(count($searchResult['results'] ?? []) > 0, 'web_search: at least one result');

$first = $searchResult['results'][0] ?? [];
assertTrue(isset($first['title'], $first['url'], $first['snippet']), 'web_search: first result has title/url/snippet');
assertTrue(str_starts_with($first['url'] ?? '', 'http'), 'web_search: first result url starts with http');

// max_results capping
$searchFew = json_decode(($search->handler)(json_encode(['query' => 'openai', 'max_results' => 3])), true);
assertTrue(count($searchFew['results'] ?? []) <= 3, 'web_search max_results=3: at most 3 results');

// Error: empty query
$searchErr = json_decode(($search->handler)(json_encode(['query' => ''])), true);
assertTrue(isset($searchErr['error']), 'web_search empty query: error key present');

// ─────────────────────────────────────────────────────────────────────────────
// OpenMeteoTool
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== OpenMeteoTool ===\n";

$meteo = new OpenMeteoTool();

$meteoResult = json_decode(($meteo->handler)(json_encode([
    'latitude' => 52.52,
    'longitude' => 13.41,
    'daily' => 'sunrise,sunset',
    'models' => 'meteofrance_seamless',
    'current' => 'is_day,temperature_2m,cloud_cover,wind_speed_10m,wind_direction_10m',
    'timezone' => 'Europe/Berlin',
    'forecast_days' => 1,
])), true);
assertTrue(is_array($meteoResult), 'open_meteo_forecast Berlin: valid JSON');
assertTrue(($meteoResult['provider'] ?? '') === 'open-meteo', 'open_meteo_forecast: provider = open-meteo');
assertTrue(($meteoResult['http_status'] ?? 0) === 200, 'open_meteo_forecast: HTTP 200');
assertTrue(isset($meteoResult['current']['temperature_2m']), 'open_meteo_forecast: current temperature present');
assertTrue(is_array($meteoResult['daily']['sunrise'] ?? null), 'open_meteo_forecast: daily sunrise array');

$meteoErrLat = json_decode(($meteo->handler)(json_encode(['latitude' => 999, 'longitude' => 0])), true);
assertTrue(isset($meteoErrLat['error']), 'open_meteo_forecast invalid latitude: error key present');

$meteoErrMissing = json_decode(($meteo->handler)(json_encode(['longitude' => 13.41])), true);
assertTrue(isset($meteoErrMissing['error']), 'open_meteo_forecast missing latitude: error key present');

// ─────────────────────────────────────────────────────────────────────────────
// LangSearchTool (only if API key provided)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n=== LangSearchTool ===\n";

$apiKey = getenv('LANGSEARCH_API_KEY');
if ($apiKey === false || $apiKey === '') {
    echo "SKIP: LANGSEARCH_API_KEY not set — skipping LangSearch tests.\n";
} else {
    $lang = new LangSearchTool($apiKey);

    // Happy path
    $langResult = json_decode(($lang->handler)(json_encode(['query' => 'PHP curl tutorial'])), true);
    assertTrue(is_array($langResult), 'langsearch: valid JSON');
    assertTrue(($langResult['provider'] ?? '') === 'langsearch', 'langsearch: provider = langsearch');
    assertTrue(is_array($langResult['results'] ?? null), 'langsearch: results is array');
    assertTrue(count($langResult['results'] ?? []) > 0, 'langsearch: at least one result');

    $firstLang = $langResult['results'][0] ?? [];
    assertTrue(isset($firstLang['url']), 'langsearch: first result has url');
    assertTrue(str_starts_with($firstLang['url'] ?? '', 'http'), 'langsearch: first result url starts with http');

    // freshness filter
    $langFresh = json_decode(($lang->handler)(json_encode(['query' => 'PHP news', 'freshness' => 'oneYear'])), true);
    assertTrue(is_array($langFresh) && !isset($langFresh['error']), 'langsearch freshness=oneYear: no error');

    // Error: bad freshness
    $langBadFresh = json_decode(($lang->handler)(json_encode(['query' => 'test', 'freshness' => 'badValue'])), true);
    assertTrue(isset($langBadFresh['error']), 'langsearch bad freshness: error key present');

    // Error: empty query
    $langErrQ = json_decode(($lang->handler)(json_encode(['query' => ''])), true);
    assertTrue(isset($langErrQ['error']), 'langsearch empty query: error key present');

    // summary=false
    $langNoSum = json_decode(($lang->handler)(json_encode(['query' => 'PHP array functions', 'summary' => false])), true);
    $firstNoSum = ($langNoSum['results'] ?? [])[0] ?? [];
    assertTrue(!isset($firstNoSum['summary']), 'langsearch summary=false: no summary key in results');
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures === 0) {
    echo "All network smoke tests passed.\n";
    exit(0);
} else {
    echo "{$failures} test(s) FAILED.\n";
    exit(1);
}
