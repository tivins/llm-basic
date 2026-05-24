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
                return 'not implemented';
            }
        );
    }

    /**
     * Reduces HTML/XHTML-like responses to plain visible text so tool results stay smaller in LLM context.
     */
    private static function htmlResponseToPlainText(string $html): string
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