<?php

declare(strict_types=1);

/**
 * 05_translate_article.php — chunk-based markdown translator (EN → FR, or any pair)
 *
 * Problem: a large markdown file may exceed the LLM context window in a single call.
 *
 * Strategy:
 *   1. Split the document at H1–H3 heading boundaries.
 *      Each section is semantically self-contained and typically 200–1 500 tokens.
 *   2. If a section still exceeds MAX_SECTION_CHARS, fall back to paragraph-level
 *      splitting, merging short consecutive paragraphs to minimise call count.
 *   3. Translate each chunk in a separate chatCompletion() call (no Agent needed).
 *   4. Append each translated chunk to the output file immediately so the run is
 *      restartable and progress is visible in real time.
 *
 * Context budget (--ctx-size 16384):
 *   ~500 tokens  system prompt (TranslatorSkill)
 *   ~30  tokens  query header ("source language / target language")
 *   ≤1 500 tokens per source chunk  (MAX_SECTION_CHARS = 6 000 chars ≈ 1 500 tokens)
 *   ≤1 500 tokens translated output (translation ≈ same length as source)
 *   ─────────────────────────────────────────────────────────────────────
 *   ≤3 530 tokens total per call  ← well within 16 384
 *
 * Usage:
 *   php examples/05_translate_article.php [source_lang] [target_lang] [input_file]
 *
 * Examples:
 *   php examples/05_translate_article.php
 *   php examples/05_translate_article.php English French
 *   php examples/05_translate_article.php English French examples/fixtures/inference_article.md
 */

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Logger;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Skills\TranslatorSkill;

require __DIR__ . '/../vendor/autoload.php';

// ~1 500 tokens — comfortably within 16 384 ctx-size even after adding system prompt
define('MAX_SECTION_CHARS', 6_000);

date_default_timezone_set('Europe/Paris');

$sourceLanguage = $argv[1] ?? 'English';
$targetLanguage = $argv[2] ?? 'French';
$inputFile      = $argv[3] ?? __DIR__ . '/fixtures/inference_article.md';

if (!file_exists($inputFile)) {
    fwrite(STDERR, "Error: Input file not found: $inputFile\n");
    exit(1);
}

$content = file_get_contents($inputFile);
if ($content === false) {
    fwrite(STDERR, "Error: Cannot read input file: $inputFile\n");
    exit(1);
}

// ── Output directory ──────────────────────────────────────────────────────────
$outputDir = __DIR__ . '/tmp/translate_' . date('YmdHis');
if (!mkdir($outputDir, 0755, true)) {
    fwrite(STDERR, "Error: Cannot create output directory: $outputDir\n");
    exit(1);
}
$outputFile = $outputDir . '/' . basename($inputFile);
file_put_contents($outputFile, '');

// ── Chunking ──────────────────────────────────────────────────────────────────
$chunks = chunkMarkdown($content, MAX_SECTION_CHARS);
$total  = count($chunks);

echo "Input:  $inputFile\n";
echo "Output: $outputFile\n";
echo "Chunks: $total\n";
echo "Translating from $sourceLanguage to $targetLanguage...\n\n";

// ── Translation loop ──────────────────────────────────────────────────────────
$llm   = new LLM('http://127.0.0.1:8080', timeoutSeconds: 600);
$skill = new TranslatorSkill();
$opts  = new ChatCompletionOptions(temperature: $skill->temperature);

foreach ($chunks as $index => $chunk) {
    $num     = $index + 1;
    $preview = mb_strimwidth(trim(strtok($chunk, "\n")), 0, 72, '...');
    echo sprintf('[%d/%d] %s', $num, $total, $preview) . "\n";

    $logger = new Logger("$outputDir/log-chunk-$num.json");
    $conv   = new Conversation(logger: $logger);
    $conv->addMessage(new Message(Role::System, $skill->prompt));
    $conv->addMessage(new Message(Role::User, $skill->formatQuery([
        'from'   => $sourceLanguage,
        'target' => $targetLanguage,
        'query'  => $chunk,
    ])));

    $response   = $llm->chatCompletion($conv, $opts);
    $translated = stripPreamble($response->firstChoice()->message->content, $chunk);

    // Separate chunks with a blank line; the first chunk needs no leading newline
    $separator = $index > 0 ? "\n" : '';
    file_put_contents($outputFile, $separator . $translated . "\n", FILE_APPEND | LOCK_EX);

    echo sprintf("        done (%.1fs, %d chars in, %d chars out)\n",
        $response->duration ?? 0.0,
        strlen($chunk),
        strlen($translated),
    );
}

echo "\nTranslation complete.\n$outputFile\n";
exit(0);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Strip any chain-of-thought or annotation preamble that the model may emit
 * before the actual translated text, despite the system prompt forbidding it.
 *
 * Heuristic: if the source chunk starts with a heading (#…), the translation
 * must also start with a heading of the same level. Everything before the first
 * such heading in the response is considered parasitic reasoning and is removed.
 * A warning is printed to STDERR so the operator can detect noisy models.
 *
 * For chunks that do not start with a heading (paragraph-level fallback splits)
 * no reliable anchor exists, so the response is returned unchanged.
 */
function stripPreamble(string $response, string $sourceChunk): string
{
    $firstSourceLine = explode("\n", ltrim($sourceChunk))[0];
    if (!preg_match('/^(#{1,6}) /', $firstSourceLine, $m)) {
        return $response;
    }

    $headingPattern = '/^' . preg_quote($m[1], '/') . ' /m';
    if (!preg_match($headingPattern, $response, $match, PREG_OFFSET_CAPTURE)) {
        return $response;
    }

    $offset = (int) $match[0][1];
    if ($offset === 0) {
        return $response;
    }

    $stripped = trim(substr($response, 0, $offset));
    fwrite(STDERR, sprintf(
        "  [warn] stripped %d-char preamble before \"%s\"\n",
        strlen($stripped),
        mb_strimwidth(substr($response, $offset, 60), 0, 60, '...'),
    ));

    return substr($response, $offset);
}

/**
 * Split a markdown document into chunks that each fit within the context window.
 *
 * Primary split: heading boundaries H1–H3.
 *   Sub-sections (H4+) stay with their parent section chunk, preserving coherence.
 *
 * Fallback: if a section still exceeds $maxChars, split by paragraph (double
 *   newline) and merge consecutive short paragraphs to reduce call count.
 *
 * Guarantee: every byte of the source ends up in exactly one chunk.
 */
function chunkMarkdown(string $content, int $maxChars): array
{
    $lines    = explode("\n", $content);
    $sections = [];
    $current  = [];

    foreach ($lines as $line) {
        if (preg_match('/^#{1,3} /', $line) && $current !== []) {
            $sections[] = implode("\n", $current);
            $current    = [];
        }
        $current[] = $line;
    }
    if ($current !== []) {
        $sections[] = implode("\n", $current);
    }

    $chunks = [];
    foreach ($sections as $section) {
        $section = rtrim($section);
        if ($section === '') {
            continue;
        }
        if (strlen($section) <= $maxChars) {
            $chunks[] = $section;
        } else {
            foreach (splitByParagraphs($section, $maxChars) as $para) {
                $chunks[] = $para;
            }
        }
    }

    return array_values($chunks);
}

/**
 * Split a text block by paragraphs (double newlines), merging consecutive short
 * paragraphs so that each returned chunk is as large as possible without exceeding
 * $maxChars. This minimises the number of LLM calls for dense sections.
 */
function splitByParagraphs(string $text, int $maxChars): array
{
    $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
    $chunks     = [];
    $current    = '';

    foreach ($paragraphs as $para) {
        $candidate = $current === '' ? $para : $current . "\n\n" . $para;
        if (strlen($candidate) <= $maxChars) {
            $current = $candidate;
        } else {
            if ($current !== '') {
                $chunks[] = $current;
            }
            $current = $para;
        }
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}
