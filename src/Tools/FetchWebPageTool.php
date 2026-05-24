<?php

namespace Tivins\LlmBasic\Tools;

use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolSchema;

// TODO implement
class FetchWebPageTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            new ToolSchema(
                'fetch_web_page',
                'Fetch a document over HTTP GET only (http/https). Response body may be truncated to stay within max_bytes. '
                . 'For HTML pages, the body is returned as plain text by default (scripts/styles removed) to save context; set raw_html true only when tag structure is required.',
                [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Absolute URL (http or https).',
                        ],
                        'max_bytes' => [
                            'type' => 'integer',
                            'description' => 'Maximum bytes of body to retain (default 524288, min 1024, max 2097152).',
                            'default' => 524288,
                        ],
                        'raw_html' => [
                            'type' => 'boolean',
                            'description' => 'If true, return the raw response body unchanged. If false (default), HTML/XHTML responses are collapsed to visible plain text.',
                            'default' => false,
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
            ),
            function (string $argumentsJson): string {
                $parameters = json_decode($argumentsJson, true) ?? [];
                $url = $parameters['url'] ?? '';
                if (!is_string($url) || $url === '') {
                    return json_encode(['error' => 'url must be a non-empty string'], JSON_UNESCAPED_UNICODE);
                }
                $parts = parse_url($url);
                if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                    return json_encode(['error' => 'invalid URL'], JSON_UNESCAPED_UNICODE);
                }

                $scheme = strtolower((string) $parts['scheme']);
                if (!in_array($scheme, ['http', 'https'], true)) {
                    return json_encode(['error' => 'only http and https URLs are allowed'], JSON_UNESCAPED_UNICODE);
                }

                $rawHtml = filter_var($parameters['raw_html'] ?? false, FILTER_VALIDATE_BOOLEAN);

                $maxBytes = isset($parameters['max_bytes']) ? (int) $parameters['max_bytes'] : 524_288;
                $maxBytes = max(1024, min(2 * 1024 * 1024, $maxBytes));

                $body = '';
                $truncated = false;

                $ch = curl_init($url);
                if ($ch === false) {
                    return json_encode(['error' => 'could not initialize HTTP client'], JSON_UNESCAPED_UNICODE);
                }

                curl_setopt_array($ch, [
                        CURLOPT_HTTPGET => true,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_USERAGENT => 'tivins/llm-php (PredefinedTools; +https://github.com/tivins/llm-php)',
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                        CURLOPT_ENCODING => '',
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
                            $len = strlen($chunk);
                            $have = strlen($body);
                            if ($have >= $maxBytes) {
                                $truncated = true;

                                return 0;
                            }

                            $space = $maxBytes - $have;
                            if ($len <= $space) {
                                $body .= $chunk;
                            } else {
                                $body .= substr($chunk, 0, $space);
                                $truncated = true;
                            }

                            return $len;
                        },
                    ]);

                curl_exec($ch);
                $err = curl_error($ch);
                $errno = curl_errno($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);

                if ($body === '' && $errno !== 0) {
                    $payload = ['error' => $err !== '' ? $err : 'request failed', 'curl_errno' => $errno];
                    return json_encode($payload, JSON_UNESCAPED_UNICODE);
                }

                $textExtracted = false;
                if (!$rawHtml && self::httpResponseLooksHtml($ctype, $body)) {
                    $body = self::htmlResponseToPlainText($body);
                    $textExtracted = true;
                }

                return json_encode([
                    'url' => $effective !== '' ? $effective : $url,
                    'http_status' => $code,
                    'content_type' => $ctype,
                    'truncated' => $truncated,
                    'text_extracted' => $textExtracted,
                    'body' => $body,
                ], JSON_UNESCAPED_UNICODE);
            }
        );
    }

    private function httpResponseLooksHtml(string $contentType, string $body): bool
    {
        $ct = strtolower($contentType);
        if ($ct !== '') {
            if (str_contains($ct, 'text/html') || str_contains($ct, 'application/xhtml+xml')) {
                return true;
            }
            if (
                str_contains($ct, 'json')
                || (str_contains($ct, 'xml') && !str_contains($ct, 'html'))
                || str_starts_with($ct, 'image/')
                || str_starts_with($ct, 'audio/')
                || str_starts_with($ct, 'video/')
                || str_contains($ct, 'octet-stream')
            ) {
                return false;
            }
        }

        $trim = ltrim($body);

        return str_starts_with($trim, '<')
            && preg_match('/^<\s*(!DOCTYPE|html|head|body|div|span|main|article|section)\b/i', $trim) === 1;
    }

    /**
     * Reduces HTML/XHTML-like responses to plain visible text so tool results stay smaller in LLM context.
     */
    private function htmlResponseToPlainText(string $html): string
    {
        $stripBlock = static function (string $markup, string $tag): string {
            $pattern = '#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is';

            return preg_replace($pattern, ' ', $markup) ?? $markup;
        };

        $html = $stripBlock($html, 'script');
        $html = $stripBlock($html, 'style');
        $html = $stripBlock($html, 'noscript');
        $html = $stripBlock($html, 'template');
        $html = preg_replace('#<script\b[^>]*>.*$#is', ' ', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*$#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}