<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

class WebSearchTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            new ToolSchema(
                'web_search',
                'Search the web via DuckDuckGo and return result entries (title, url, snippet). Use fetch_web_page to read the full body of a returned URL.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results to return (default 8, max 20).',
                            'default' => 8,
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $query = (string) ($args['query'] ?? '');

                if ($query === '') {
                    return json_encode(['error' => 'query must be a non-empty string'], JSON_UNESCAPED_UNICODE);
                }

                $maxResults = isset($args['max_results']) ? (int) $args['max_results'] : 8;
                $maxResults = max(1, min(20, $maxResults));

                $fetch = self::fetchDdgHtml($query);
                if ($fetch['error'] !== null) {
                    return json_encode($fetch['error'], JSON_UNESCAPED_UNICODE);
                }

                $body    = $fetch['body'];
                $code    = $fetch['http_status'];
                $results = self::parseDdgHtmlResults($body, $maxResults);

                if ($results === []) {
                    return json_encode([
                        'error'       => 'DuckDuckGo returned no search results',
                        'http_status' => $code,
                    ], JSON_UNESCAPED_UNICODE);
                }

                return json_encode([
                    'provider'    => 'duckduckgo',
                    'query'       => $query,
                    'results'     => $results,
                    'http_status' => $code,
                ], JSON_UNESCAPED_UNICODE);
            }
        );
    }

    /**
     * @return array{body: string, http_status: int, error: null}|array{body: string, http_status: int, error: array<string, mixed>}
     */
    private static function fetchDdgHtml(string $query): array
    {
        $strategies = [
            static fn (string $q): array => self::curlDdgGet($q),
            static fn (string $q): array => self::curlDdgPost($q),
        ];

        $last = ['body' => '', 'http_status' => 0, 'curl_errno' => 0, 'curl_error' => ''];

        foreach ($strategies as $fetch) {
            $last = $fetch($query);
            if (self::hasParseableDdgResults($last['body'], $last['http_status'])) {
                return [
                    'body'        => $last['body'],
                    'http_status' => $last['http_status'],
                    'error'       => null,
                ];
            }
        }

        if ($last['body'] === '' && $last['curl_errno'] !== 0) {
            return [
                'body'        => '',
                'http_status' => $last['http_status'],
                'error'       => [
                    'error'       => $last['curl_error'] !== '' ? $last['curl_error'] : 'empty response',
                    'http_status' => $last['http_status'],
                    'curl_errno'  => $last['curl_errno'],
                ],
            ];
        }

        if ($last['http_status'] === 202 || str_contains($last['body'], 'anomaly.js')) {
            return [
                'body'        => $last['body'],
                'http_status' => $last['http_status'],
                'error'       => [
                    'error'       => 'DuckDuckGo blocked the request (bot detection). Retry later or use langsearch_web_search if LANGSEARCH_API_KEY is configured.',
                    'http_status' => $last['http_status'],
                ],
            ];
        }

        if ($last['http_status'] === 200 && $last['body'] !== '') {
            return [
                'body'        => $last['body'],
                'http_status' => $last['http_status'],
                'error'       => [
                    'error'       => 'DuckDuckGo returned no search results',
                    'http_status' => $last['http_status'],
                ],
            ];
        }

        return [
            'body'        => $last['body'],
            'http_status' => $last['http_status'],
            'error'       => [
                'error'       => 'unexpected HTTP status',
                'http_status' => $last['http_status'],
            ],
        ];
    }

    /**
     * @return array{body: string, http_status: int, curl_errno: int, curl_error: string}
     */
    private static function curlDdgGet(string $query): array
    {
        $url = 'https://html.duckduckgo.com/html/?' . http_build_query(['q' => $query]);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['body' => '', 'http_status' => 0, 'curl_errno' => -1, 'curl_error' => 'could not initialize HTTP client'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; tivins/llm-php; +https://github.com/tivins/llm-php)',
        ] + self::httpSslCurlOpts());

        $body  = curl_exec($ch);
        $err   = curl_error($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'body'        => is_string($body) ? $body : '',
            'http_status' => $code,
            'curl_errno'  => $errno,
            'curl_error'  => $err,
        ];
    }

    /**
     * @return array{body: string, http_status: int, curl_errno: int, curl_error: string}
     */
    private static function curlDdgPost(string $query): array
    {
        $ch = curl_init('https://html.duckduckgo.com/html/');
        if ($ch === false) {
            return ['body' => '', 'http_status' => 0, 'curl_errno' => -1, 'curl_error' => 'could not initialize HTTP client'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['q' => $query, 'b' => '', 'kl' => '']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; tivins/llm-php; +https://github.com/tivins/llm-php)',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: https://html.duckduckgo.com',
                'Referer: https://html.duckduckgo.com/',
            ],
        ] + self::httpSslCurlOpts());

        $body  = curl_exec($ch);
        $err   = curl_error($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'body'        => is_string($body) ? $body : '',
            'http_status' => $code,
            'curl_errno'  => $errno,
            'curl_error'  => $err,
        ];
    }

    private static function hasParseableDdgResults(string $body, int $code): bool
    {
        if ($code !== 200 || $body === '' || str_contains($body, 'anomaly.js')) {
            return false;
        }

        return preg_match(
            '/<a\b[^>]*\bclass="result__a"[^>]*\bhref="[^"]*\/l\/\?uddg=[^"]+"/si',
            $body,
        ) === 1;
    }

    /**
     * @return array<int, mixed>
     */
    private static function httpSslCurlOpts(): array
    {
        $opts = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        return $opts;
    }

    /**
     * @return list<array{title: string, url: string, snippet: string}>
     */
    private static function parseDdgHtmlResults(string $html, int $max): array
    {
        $results = [];

        preg_match_all(
            '/<a\b[^>]*\bclass="result__a"[^>]*\bhref="[^"]*\/l\/\?uddg=([^"&]+)[^"]*"[^>]*>(.*?)<\/a>/si',
            $html,
            $titleMatches,
            PREG_SET_ORDER,
        );

        preg_match_all(
            '/<a\b[^>]*\bclass="result__snippet"[^>]*>(.*?)<\/a>/si',
            $html,
            $snippetMatches,
            PREG_SET_ORDER,
        );

        foreach ($titleMatches as $i => $m) {
            if (count($results) >= $max) {
                break;
            }

            $url = urldecode($m[1]);
            if (!str_starts_with($url, 'http')) {
                continue;
            }

            $title = trim(preg_replace(
                '/\s+/',
                ' ',
                strip_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ) ?? '');

            if ($title === '') {
                continue;
            }

            $snippet = '';
            if (isset($snippetMatches[$i][1])) {
                $snippet = trim(preg_replace(
                    '/\s+/',
                    ' ',
                    strip_tags(html_entity_decode($snippetMatches[$i][1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ) ?? '');
            }

            $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
        }

        return $results;
    }
}
