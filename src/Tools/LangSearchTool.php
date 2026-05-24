<?php

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

class LangSearchTool extends Tool
{
    public function __construct(
        private readonly string $apiKey,
    )
    {
        parent::__construct(
            new ToolSchema(
                'langsearch_web_search',
                'Search the web via LangSearch API (richer snippets and optional summaries). Requires a LangSearch API key. '
                . 'Quota per session: QPS 1, QPM 60, QPD 1000 — when exhausted, use web_search (DuckDuckGo) instead. '
                . 'Use fetch_web_page to read the full body of a returned URL.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results to return (default 8, max 10).',
                            'default' => 8,
                        ],
                        'freshness' => [
                            'type' => 'string',
                            'description' => 'Time filter: oneDay, oneWeek, oneMonth, oneYear, or noLimit (default).',
                            'default' => 'noLimit',
                        ],
                        'summary' => [
                            'type' => 'boolean',
                            'description' => 'Include LangSearch long-text summaries when available (default true).',
                            'default' => true,
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
                $args = json_decode($argumentsJson, true) ?? [];
                $query = (string)($args['query'] ?? '');

                if ($query === '') {
                    return $this->formatError('query must be a non-empty string');
                }
                $maxResults = (int)($args['max_results'] ?? 8);
                $maxResults = max(1, min(10, $maxResults));

                $freshness = $args['freshness'] ?? 'noLimit';
                if (!in_array($freshness, [
                    'oneDay',
                    'oneWeek',
                    'oneMonth',
                    'oneYear',
                    'noLimit',
                ], true)) {
                    return $this->formatError('freshness must be oneDay, oneWeek, oneMonth, oneYear, or noLimit');
                }
                $summary = true;
                if (array_key_exists('summary', $args)) {
                    $summary = filter_var($args['summary'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($summary === null) {
                        return $this->formatError('summary must be a boolean');
                    }
                }
                $payload = json_encode([
                    'query' => $query,
                    'freshness' => $freshness,
                    'summary' => $summary,
                    'count' => $maxResults,
                ], JSON_THROW_ON_ERROR);

                $ch = curl_init('https://api.langsearch.com/v1/web-search');
                if ($ch === false) {
                    return $this->formatError('could not initialize HTTP client');
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_ENCODING => '',
                    CURLOPT_USERAGENT => 'tivins/llm-php (PredefinedTools; +https://github.com/tivins/llm-php)',
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);

                $body = curl_exec($ch);
                $err = curl_error($ch);
                $errno = curl_errno($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);

                $error_payload = [
                    'http_code' => $code,
                    'errno' => $errno,
                    'errmsg' => $err,
                ];

                if ($body === false || $body === '') {
                    return $this->formatError($error_payload + [
                        'error' => 'could not initialize HTTP client',
                    ]);
                }
                if ($code === 429) {
                    return $this->formatError($error_payload + [
                        'error' => 'LangSearch API returned HTTP 429 (rate limit).'
                    ]);
                }
                $decoded = json_decode((string) $body, true);
                if (!is_array($decoded)) {
                    return $this->formatError($error_payload + ['error' => 'invalid JSON response']);
                }
                $apiCode = $decoded['code'] ?? null;
                if ($apiCode !== 200 && $apiCode !== '200') {
                    $msg = is_string($decoded['msg'] ?? null) ? $decoded['msg'] : 'LangSearch API error';
                    return $this->formatError($error_payload + ['error' => $msg]);
                }$values = $decoded['data']['webPages']['value'] ?? [];
                if (!is_array($values)) {
                    $values = [];
                }

                /** @var list<array{title: string, url: string, snippet: string, summary?: string}> $results */
                $results = [];
                foreach ($values as $entry) {
                    if (!is_array($entry) || count($results) >= $maxResults) {
                        continue;
                    }

                    $url = $entry['url'] ?? '';
                    $title = $entry['name'] ?? '';
                    if (!is_string($url) || $url === '' || !str_starts_with($url, 'http')) {
                        continue;
                    }
                    if (!is_string($title) || $title === '') {
                        continue;
                    }

                    $snippet = is_string($entry['snippet'] ?? null) ? $entry['snippet'] : '';
                    $row = [
                        'title' => $title,
                        'url' => $url,
                        'snippet' => $snippet,
                    ];
                    if ($summary && is_string($entry['summary'] ?? null) && $entry['summary'] !== '') {
                        $row['summary'] = $entry['summary'];
                    }
                    $results[] = $row;
                }

                return json_encode([
                    'provider' => 'langsearch',
                    'results' => $results,
                    'http_status' => $code,
                    'query' => $query,
                ], JSON_UNESCAPED_UNICODE);
            }
        );
    }

    private function formatError(string|array $error): string
    {
        return json_encode(is_array($error) ? $error : ['error' => $error], JSON_UNESCAPED_UNICODE);
    }
}