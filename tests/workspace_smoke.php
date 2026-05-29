<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tivins\LlmBasic\Skills\WriterSkill;
use Tivins\LlmBasic\Tools\ListDirTool;
use Tivins\LlmBasic\Tools\ReadFileTool;
use Tivins\LlmBasic\Tools\ReadFileRangeTool;
use Tivins\LlmBasic\Tools\ApplyPatchTool;
use Tivins\LlmBasic\Tools\ApplyDiffTool;
use Tivins\LlmBasic\Tools\WriteFileTool;
use Tivins\LlmBasic\Tools\AppendFileTool;
use Tivins\LlmBasic\Tools\LintFileTool;
use Tivins\LlmBasic\Tools\GetDateTimeTool;
use Tivins\LlmBasic\Tools\GrepTool;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

$root = dirname(__DIR__);
$workspace = new Workspace($root);
$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    } else {
        echo "OK: {$message}\n";
    }
}

function assertThrows(callable $fn, string $contains, string $message): void
{
    try {
        $fn();
        assertTrue(false, "{$message} (expected exception)");
    } catch (WorkspaceException $e) {
        assertTrue(
            str_contains($e->getMessage(), $contains),
            "{$message} (\"{$e->getMessage()}\")",
        );
    }
}

// resolveDirectory: root
assertTrue($workspace->resolveDirectory('') === $workspace->root(), 'resolveDirectory empty = root');
assertTrue($workspace->resolveDirectory('.') === $workspace->root(), 'resolveDirectory . = root');

// listDir: root
$rootListing = $workspace->listDir('');
assertTrue($rootListing['path'] === '.', 'listDir root path is "."');
assertTrue(
    in_array('src', array_column($rootListing['entries'], 'name'), true),
    'listDir root contains src',
);

// listDir: subdirectory
$srcListing = $workspace->listDir('src');
assertTrue($srcListing['path'] === 'src', 'listDir src path');
assertTrue(
    in_array('Agent.php', array_column($srcListing['entries'], 'name'), true),
    'listDir src contains Agent.php',
);
$agentEntry = null;
foreach ($srcListing['entries'] as $entry) {
    if ($entry['name'] === 'Agent.php') {
        $agentEntry = $entry;
        break;
    }
}
assertTrue($agentEntry !== null && $agentEntry['type'] === 'file', 'Agent.php is a file');

// traversal blocked
assertThrows(
    fn () => $workspace->resolve('../../../etc/passwd'),
    'not found',
    'read_file traversal outside workspace',
);

// read_file still works
$content = $workspace->read('composer.json');
assertTrue(str_contains($content, '"name": "tivins/llm-basic"'), 'read composer.json');

// list_dir on missing path
assertThrows(
    fn () => $workspace->listDir('no-such-directory'),
    'Directory not found',
    'listDir missing directory',
);

// list_dir on file path
assertThrows(
    fn () => $workspace->listDir('composer.json'),
    'Not a directory',
    'listDir on file fails',
);

// ListDirTool JSON handler
$listDirTool = new ListDirTool($workspace);
$json = ($listDirTool->handler)(json_encode(['path' => 'src']));
$decoded = json_decode($json, true);
assertTrue(is_array($decoded) && ($decoded['path'] ?? '') === 'src', 'ListDirTool returns src listing');
assertTrue(!isset($decoded['error']), 'ListDirTool no error on src');

$jsonError = ($listDirTool->handler)(json_encode(['path' => '../../../etc']));
$errorDecoded = json_decode($jsonError, true);
assertTrue(isset($errorDecoded['error']), 'ListDirTool returns JSON error for escape attempt');

// ReadFileTool JSON error for traversal
$readFileTool = new ReadFileTool($workspace);
$readJson = ($readFileTool->handler)(json_encode(['file' => '../../../etc/passwd']));
$readDecoded = json_decode($readJson, true);
assertTrue(isset($readDecoded['error']), 'ReadFileTool JSON error for traversal');

// read_file_range
$rangeFile = 'tests/_tmp/smoke-range.txt';
$rangeLines = [];
for ($i = 1; $i <= 10; $i++) {
    $rangeLines[] = "line {$i}";
}
$workspace->write($rangeFile, implode("\n", $rangeLines) . "\n");

$rangeResult = $workspace->readRange($rangeFile, offset: 3, limit: 4);
assertTrue($rangeResult['start_line'] === 3, 'readRange start_line');
assertTrue($rangeResult['end_line'] === 6, 'readRange end_line');
assertTrue($rangeResult['total_lines'] === 10, 'readRange total_lines');
assertTrue($rangeResult['content'] === "line 3\nline 4\nline 5\nline 6", 'readRange content');
assertTrue($rangeResult['truncated'] === true, 'readRange truncated when more lines remain');

$rangeTail = $workspace->readRange($rangeFile, offset: 9, limit: 10);
assertTrue($rangeTail['truncated'] === true, 'readRange truncated when offset skips earlier lines');
assertTrue($rangeTail['content'] === "line 9\nline 10", 'readRange tail content');

$tailDefault = $workspace->readRange($rangeFile);
assertTrue($tailDefault['start_line'] === 1, 'readRange tail default start_line for short file');
assertTrue($tailDefault['end_line'] === 10, 'readRange tail default end_line for short file');
assertTrue($tailDefault['truncated'] === false, 'readRange tail default not truncated for short file');

$longRangeFile = 'tests/_tmp/smoke-range-long.txt';
$longRangeLines = [];
for ($i = 1; $i <= 250; $i++) {
    $longRangeLines[] = "line {$i}";
}
$workspace->write($longRangeFile, implode("\n", $longRangeLines) . "\n");

$longTail = $workspace->readRange($longRangeFile);
assertTrue($longTail['start_line'] === 51, 'readRange tail default start_line for long file');
assertTrue($longTail['end_line'] === 250, 'readRange tail default end_line for long file');
assertTrue($longTail['truncated'] === true, 'readRange tail default truncated for long file');
assertTrue(
    $longTail['content'] === implode("\n", array_slice($longRangeLines, 50)),
    'readRange tail default content for long file',
);

$emptyRangeFile = 'tests/_tmp/smoke-range-empty.txt';
$workspace->write($emptyRangeFile, '');
$emptyRange = $workspace->readRange($emptyRangeFile);
assertTrue($emptyRange['total_lines'] === 0, 'readRange empty file total_lines');
assertTrue($emptyRange['content'] === '', 'readRange empty file content');

assertThrows(
    fn () => $workspace->readRange($rangeFile, offset: 0),
    'offset must be at least 1',
    'readRange invalid offset',
);

$readFileRangeTool = new ReadFileRangeTool($workspace);
$rangeToolJson = ($readFileRangeTool->handler)(json_encode([
    'file' => $rangeFile,
    'offset' => 2,
    'limit' => 2,
]));
$rangeToolDecoded = json_decode($rangeToolJson, true);
assertTrue(
    is_array($rangeToolDecoded)
    && ($rangeToolDecoded['content'] ?? '') === "line 2\nline 3"
    && !isset($rangeToolDecoded['error']),
    'ReadFileRangeTool success JSON',
);

$rangeTailToolJson = ($readFileRangeTool->handler)(json_encode(['file' => $longRangeFile]));
$rangeTailToolDecoded = json_decode($rangeTailToolJson, true);
assertTrue(
    is_array($rangeTailToolDecoded)
    && ($rangeTailToolDecoded['start_line'] ?? 0) === 51
    && ($rangeTailToolDecoded['truncated'] ?? false) === true
    && !isset($rangeTailToolDecoded['error']),
    'ReadFileRangeTool tail mode JSON',
);

$rangeTraversalJson = ($readFileRangeTool->handler)(json_encode([
    'file' => '../../../etc/passwd',
]));
assertTrue(
    isset(json_decode($rangeTraversalJson, true)['error']),
    'ReadFileRangeTool JSON error for traversal',
);

// write_file: create under tests/_tmp/
$tmpDir = $root . '/tests/_tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}
$tmpFile = 'tests/_tmp/smoke-write.txt';
$writePayload = 'smoke test content';
$writeResult = $workspace->write($tmpFile, $writePayload);
assertTrue($writeResult['created'] === true, 'write created new file');
assertTrue($writeResult['bytes_written'] === strlen($writePayload), 'write bytes_written');
assertTrue($writeResult['file'] === $tmpFile, 'write display path');
assertTrue($workspace->read($tmpFile) === $writePayload, 'write content readable via read()');

// write_file: overwrite false on existing file
assertThrows(
    fn () => $workspace->write($tmpFile, 'other', overwrite: false),
    'already exists',
    'write overwrite false on existing file',
);

// write_file: traversal blocked
$writeFileTool = new WriteFileTool($workspace);
$writeTraversalJson = ($writeFileTool->handler)(json_encode([
    'file' => '../../../etc/passwd',
    'content' => 'evil',
]));
$writeTraversalDecoded = json_decode($writeTraversalJson, true);
assertTrue(isset($writeTraversalDecoded['error']), 'WriteFileTool JSON error for traversal');

// WriteFileTool success JSON
$writeToolJson = ($writeFileTool->handler)(json_encode([
    'file' => $tmpFile,
    'content' => 'via tool',
    'overwrite' => true,
]));
$writeToolDecoded = json_decode($writeToolJson, true);
assertTrue(
    is_array($writeToolDecoded)
    && ($writeToolDecoded['bytes_written'] ?? 0) === strlen('via tool')
    && !isset($writeToolDecoded['error']),
    'WriteFileTool returns success JSON',
);
assertTrue($workspace->read($tmpFile) === 'via tool', 'WriteFileTool content applied');

// WriteFileTool overwrite false
$noOverwriteJson = ($writeFileTool->handler)(json_encode([
    'file' => $tmpFile,
    'content' => 'nope',
    'overwrite' => false,
]));
$noOverwriteDecoded = json_decode($noOverwriteJson, true);
assertTrue(isset($noOverwriteDecoded['error']), 'WriteFileTool overwrite false returns error');

// append_file: extend existing file
$appendFile = 'tests/_tmp/smoke-append.txt';
$workspace->write($appendFile, "line one\n");
$appendResult = $workspace->append($appendFile, "line two\n");
assertTrue($appendResult['created'] === false, 'append on existing file');
assertTrue($appendResult['bytes_appended'] === strlen("line two\n"), 'append bytes_appended');
assertTrue($appendResult['bytes_total'] === strlen("line one\nline two\n"), 'append bytes_total');
assertTrue($workspace->read($appendFile) === "line one\nline two\n", 'append content');

// append_file: create when missing
$appendNewFile = 'tests/_tmp/smoke-append-new.txt';
$appendCreateResult = $workspace->append($appendNewFile, 'first line');
assertTrue($appendCreateResult['created'] === true, 'append creates missing file');
assertTrue($workspace->read($appendNewFile) === 'first line', 'append new file content');

// append_file: create_if_missing false on missing file
assertThrows(
    fn () => $workspace->append('tests/_tmp/missing-append.txt', 'x', createIfMissing: false),
    'not found',
    'append create_if_missing false',
);

$appendFileTool = new AppendFileTool($workspace);
$appendToolJson = ($appendFileTool->handler)(json_encode([
    'file' => $appendFile,
    'content' => 'line three',
]));
$appendToolDecoded = json_decode($appendToolJson, true);
assertTrue(
    is_array($appendToolDecoded)
    && ($appendToolDecoded['bytes_appended'] ?? 0) === strlen('line three')
    && !isset($appendToolDecoded['error']),
    'AppendFileTool returns success JSON',
);

// apply_patch
$patchFile = 'tests/_tmp/smoke-patch.txt';
$workspace->write($patchFile, "line one\nline two\nline two\n");
$patchResult = $workspace->applySearchReplace($patchFile, 'line one', 'line 1');
assertTrue($patchResult['replacements'] === 1, 'apply_patch single replacement count');
assertTrue(
    $workspace->read($patchFile) === "line 1\nline two\nline two\n",
    'apply_patch content updated',
);

assertThrows(
    fn () => $workspace->applySearchReplace($patchFile, 'missing', 'x'),
    'not found',
    'apply_patch old_string absent',
);

assertThrows(
    fn () => $workspace->applySearchReplace($patchFile, 'line two', 'dup'),
    'multiple times',
    'apply_patch ambiguous without replace_all',
);

$replaceAllResult = $workspace->applySearchReplace($patchFile, 'line two', 'L2', replaceAll: true);
assertTrue($replaceAllResult['replacements'] === 2, 'apply_patch replace_all count');

$applyPatchTool = new ApplyPatchTool($workspace);
$patchToolJson = ($applyPatchTool->handler)(json_encode([
    'file' => $patchFile,
    'old_string' => 'L2',
    'new_string' => 'line 2',
    'replace_all' => true,
]));
$patchToolDecoded = json_decode($patchToolJson, true);
assertTrue(
    is_array($patchToolDecoded)
    && ($patchToolDecoded['replacements'] ?? 0) === 2
    && !isset($patchToolDecoded['error']),
    'ApplyPatchTool success JSON',
);

$patchNotFoundJson = ($applyPatchTool->handler)(json_encode([
    'file' => $patchFile,
    'old_string' => 'nope',
    'new_string' => 'x',
]));
assertTrue(
    isset(json_decode($patchNotFoundJson, true)['error']),
    'ApplyPatchTool JSON error when old_string missing',
);

// lint_file
$validPhp = 'tests/_tmp/smoke-valid.php';
$invalidPhp = 'tests/_tmp/smoke-invalid.php';
$workspace->write($validPhp, "<?php\nreturn 1;\n");
$workspace->write($invalidPhp, "<?php\nclass {\n");

$lintValid = $workspace->lintFile($validPhp);
assertTrue($lintValid['valid'] === true, 'lintFile valid PHP');
assertTrue($lintValid['language'] === 'php', 'lintFile detects php');
assertTrue($lintValid['errors'] === [], 'lintFile no errors on valid file');

$lintInvalid = $workspace->lintFile($invalidPhp);
assertTrue($lintInvalid['valid'] === false, 'lintFile invalid PHP');
assertTrue($lintInvalid['errors'] !== [], 'lintFile returns errors on invalid file');

assertThrows(
    fn () => $workspace->lintFile('composer.json'),
    'Could not detect language',
    'lintFile unknown extension without language',
);

assertThrows(
    fn () => $workspace->lintFile($validPhp, 'ruby'),
    'Unsupported language',
    'lintFile unsupported language',
);

$lintFileTool = new LintFileTool($workspace);
$lintToolJson = ($lintFileTool->handler)(json_encode(['file' => $validPhp]));
$lintToolDecoded = json_decode($lintToolJson, true);
assertTrue(
    is_array($lintToolDecoded)
    && ($lintToolDecoded['valid'] ?? false) === true
    && !isset($lintToolDecoded['error']),
    'LintFileTool success JSON',
);

$lintTraversalJson = ($lintFileTool->handler)(json_encode(['file' => '../../../etc/passwd']));
assertTrue(
    isset(json_decode($lintTraversalJson, true)['error']),
    'LintFileTool JSON error for traversal',
);

// grep
$grepSample = 'tests/_tmp/smoke-grep.txt';
$workspace->write($grepSample, "alpha line\nBETA line\nalpha again\n");
$fileGrep = $workspace->grep('alpha', $grepSample);
assertTrue($fileGrep['match_count'] === 2, 'grep single file match_count');
assertTrue($fileGrep['path'] === $grepSample, 'grep single file path');
assertTrue(
    ($fileGrep['matches'][0]['line'] ?? 0) === 1
    && ($fileGrep['matches'][1]['line'] ?? 0) === 3,
    'grep single file line numbers',
);

$caseGrep = $workspace->grep('beta', $grepSample, caseInsensitive: true);
assertTrue($caseGrep['match_count'] === 1, 'grep case_insensitive');

$dirGrep = $workspace->grep('alpha', 'tests/_tmp');
assertTrue($dirGrep['match_count'] === 2, 'grep directory match_count');

$globGrep = $workspace->grep('alpha', 'tests/_tmp', glob: '*.txt');
assertTrue($globGrep['match_count'] === 2, 'grep directory glob filter');

assertThrows(
    fn () => $workspace->grep('[', $grepSample),
    'Invalid regex pattern',
    'grep invalid regex',
);

$storyFile = 'tmp/the-story-of-computing-and-information.md';
if (is_file($root . '/' . $storyFile)) {
    $storyGrep = $workspace->grep('Shannon', $storyFile, maxMatches: 3);
    assertTrue($storyGrep['match_count'] >= 1, 'grep story file finds Shannon');
    assertTrue($storyGrep['truncated'] === true, 'grep story file truncated at max_matches');
}

$grepTool = new GrepTool($workspace);
$grepToolJson = ($grepTool->handler)(json_encode([
    'pattern' => 'alpha',
    'path' => $grepSample,
]));
$grepToolDecoded = json_decode($grepToolJson, true);
assertTrue(
    is_array($grepToolDecoded)
    && ($grepToolDecoded['match_count'] ?? 0) === 2
    && !isset($grepToolDecoded['error']),
    'GrepTool success JSON',
);

$grepTraversalJson = ($grepTool->handler)(json_encode([
    'pattern' => 'root',
    'path' => '../../../etc',
]));
assertTrue(
    isset(json_decode($grepTraversalJson, true)['error']),
    'GrepTool JSON error for traversal',
);

$grepMissingPatternJson = ($grepTool->handler)(json_encode(['path' => $grepSample]));
assertTrue(
    isset(json_decode($grepMissingPatternJson, true)['error']),
    'GrepTool JSON error when pattern missing',
);

// apply_diff — happy path: single hunk, LF content
$diffFile = 'tests/_tmp/smoke-diff.txt';
$workspace->write($diffFile, "alpha\nbeta\ngamma\n");

$diff1 = <<<'DIFF'
@@ -1,3 +1,3 @@
 alpha
-beta
+BETA
 gamma
DIFF;

$diffResult = $workspace->applyDiff($diffFile, $diff1);
assertTrue($diffResult['hunks_applied'] === 1, 'applyDiff single hunk count');
assertTrue($workspace->read($diffFile) === "alpha\nBETA\ngamma\n", 'applyDiff content updated');

// apply_diff — multi-hunk
$workspace->write($diffFile, "one\ntwo\nthree\nfour\nfive\n");

$diff2 = <<<'DIFF'
@@ -1,2 +1,2 @@
-one
+ONE
 two
@@ -4,2 +4,2 @@
 four
-five
+FIVE
DIFF;

$diffResult2 = $workspace->applyDiff($diffFile, $diff2);
assertTrue($diffResult2['hunks_applied'] === 2, 'applyDiff multi-hunk count');
assertTrue(
    $workspace->read($diffFile) === "ONE\ntwo\nthree\nfour\nFIVE\n",
    'applyDiff multi-hunk content',
);

// apply_diff — CRLF file, LF diff (line-ending preservation)
$workspace->write($diffFile, "line1\r\nline2\r\nline3\r\n");
$diff3 = "@@ -1,3 +1,3 @@\n line1\n-line2\n+LINE2\n line3\n";
$workspace->applyDiff($diffFile, $diff3);
assertTrue(
    $workspace->read($diffFile) === "line1\r\nLINE2\r\nline3\r\n",
    'applyDiff preserves CRLF line endings',
);

// apply_diff — CRLF diff on LF file (diff normalisation)
$workspace->write($diffFile, "hello\nworld\n");
$diffCrlf = "@@ -1,2 +1,2 @@\r\n hello\r\n-world\r\n+WORLD\r\n";
$workspace->applyDiff($diffFile, $diffCrlf);
assertTrue(
    $workspace->read($diffFile) === "hello\nWORLD\n",
    'applyDiff CRLF diff normalised correctly',
);

// apply_diff — diff with --- / +++ header lines (optional, must be ignored)
$workspace->write($diffFile, "foo\nbar\n");
$diffWithHeader = <<<'DIFF'
--- a/file.txt
+++ b/file.txt
@@ -1,2 +1,2 @@
-foo
+FOO
 bar
DIFF;
$workspace->applyDiff($diffFile, $diffWithHeader);
assertTrue($workspace->read($diffFile) === "FOO\nbar\n", 'applyDiff ignores --- / +++ header lines');

// apply_diff — context mismatch returns precise error
$workspace->write($diffFile, "a\nb\nc\n");
$badDiff = "@@ -1,2 +1,2 @@\n-WRONG\n+x\n b\n";
assertThrows(
    fn () => $workspace->applyDiff($diffFile, $badDiff),
    'does not apply',
    'applyDiff context mismatch gives error',
);

// apply_diff — no hunks in diff
assertThrows(
    fn () => $workspace->applyDiff($diffFile, "--- a/file\n+++ b/file\n"),
    'No hunks found',
    'applyDiff no hunks throws',
);

// apply_diff — invalid hunk header
assertThrows(
    fn () => $workspace->applyDiff($diffFile, "@@ broken header @@\n-a\n+b\n"),
    'Invalid hunk header',
    'applyDiff invalid hunk header throws',
);

// ApplyDiffTool — JSON happy path
$workspace->write($diffFile, "x\ny\n");
$applyDiffTool = new ApplyDiffTool($workspace);
$diffToolJson = ($applyDiffTool->handler)(json_encode([
    'file' => $diffFile,
    'diff' => "@@ -1,2 +1,2 @@\n x\n-y\n+Y\n",
]));
$diffToolDecoded = json_decode($diffToolJson, true);
assertTrue(
    is_array($diffToolDecoded)
    && ($diffToolDecoded['hunks_applied'] ?? 0) === 1
    && !isset($diffToolDecoded['error']),
    'ApplyDiffTool success JSON',
);

// ApplyDiffTool — mismatch returns JSON error (not exception)
$workspace->write($diffFile, "x\ny\n");
$diffToolErrorJson = ($applyDiffTool->handler)(json_encode([
    'file' => $diffFile,
    'diff' => "@@ -1,1 +1,1 @@\n-WRONG\n+Y\n",
]));
$diffToolErrorDecoded = json_decode($diffToolErrorJson, true);
assertTrue(
    isset($diffToolErrorDecoded['error'])
    && str_contains($diffToolErrorDecoded['error'], 'does not apply'),
    'ApplyDiffTool mismatch returns JSON error with details',
);

// ApplyDiffTool — traversal blocked
$diffTraversalJson = ($applyDiffTool->handler)(json_encode([
    'file' => '../../../etc/passwd',
    'diff' => "@@ -1,1 +1,1 @@\n-root\n+evil\n",
]));
assertTrue(
    isset(json_decode($diffTraversalJson, true)['error']),
    'ApplyDiffTool JSON error for traversal',
);

$writerSkill = new WriterSkill();
$writerPlanFile = 'tests/_tmp/writer-plan.md';
$writerArticleFile = 'tests/_tmp/writer-article.md';
$workspace->write($writerPlanFile, "### 1. Intro\n### 2. Body\n");
$workspace->write($writerArticleFile, "## 1. Intro\n");
assertTrue(
    !$writerSkill->isArticleComplete($workspace, $writerPlanFile, $writerArticleFile),
    'WriterSkill article incomplete with missing sections',
);
$workspace->append($writerArticleFile, "## 2. Body\n");
assertTrue(
    $writerSkill->isArticleComplete($workspace, $writerPlanFile, $writerArticleFile),
    'WriterSkill article complete when all plan sections exist',
);

$writerPlanFile2 = 'tests/_tmp/writer-plan-titles.md';
$writerArticleFile2 = 'tests/_tmp/writer-article-titles.md';
$workspace->write($writerPlanFile2, "### 1. Intro\n### 2. Body\n");
$workspace->write($writerArticleFile2, "## Introduction\n## Conclusion\n");
assertTrue(
    $writerSkill->isArticleComplete($workspace, $writerPlanFile2, $writerArticleFile2),
    'WriterSkill article complete with unnumbered section headings',
);

$writerProgress = $writerSkill->articleProgress($workspace, $writerPlanFile2, $writerArticleFile2);
assertTrue(
    is_array($writerProgress)
    && $writerProgress['plan_sections'] === 2
    && $writerProgress['article_sections'] === 2
    && $writerProgress['complete'] === true
    && $writerProgress['next_plan_section'] === null,
    'WriterSkill articleProgress reports complete article',
);

$writerPlanFile3 = 'tests/_tmp/writer-plan-progress.md';
$writerArticleFile3 = 'tests/_tmp/writer-article-progress.md';
$workspace->write($writerPlanFile3, "### 1. Intro\n### 2. Body\n### 3. Outro\n");
$workspace->write($writerArticleFile3, "## 1. Intro\n");
$writerPartialProgress = $writerSkill->articleProgress($workspace, $writerPlanFile3, $writerArticleFile3);
assertTrue(
    is_array($writerPartialProgress)
    && $writerPartialProgress['plan_sections'] === 3
    && $writerPartialProgress['article_sections'] === 1
    && $writerPartialProgress['complete'] === false
    && $writerPartialProgress['next_plan_section'] === 'Body',
    'WriterSkill articleProgress reports next plan section',
);

$continueQuery = $writerSkill->formatQuery([
    'step' => 'continue',
    'plan_file' => $writerPlanFile3,
    'article_file' => $writerArticleFile3,
    'iteration' => 2,
    'progress' => $writerPartialProgress,
    'last_step_summary' => "Wrote section 1.\nNext: Body.",
]);
assertTrue(
    str_contains($continueQuery, 'progress: 1/3 plan sections written in the article.')
    && str_contains($continueQuery, 'next_plan_section: "### 2. Body"')
    && str_contains($continueQuery, 'previous_step_reported: "Wrote section 1. Next: Body."'),
    'WriterSkill continue query includes progress and previous summary hints',
);

echo "\n=== GetDateTimeTool ===\n";

$dateTimeTool = new GetDateTimeTool();
$dateTimeDefault = json_decode(($dateTimeTool->handler)('{}'), true);
assertTrue(is_array($dateTimeDefault), 'get_date_time default: valid JSON');
assertTrue(
    isset($dateTimeDefault['datetime'], $dateTimeDefault['timezone'], $dateTimeDefault['unix_timestamp'])
    && !isset($dateTimeDefault['error']),
    'get_date_time default: datetime keys present',
);
assertTrue(
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) ($dateTimeDefault['datetime'] ?? '')) === 1,
    'get_date_time default: datetime is ISO-8601 ATOM',
);

$dateTimeUtc = json_decode(($dateTimeTool->handler)(json_encode(['timezone' => 'UTC'])), true);
assertTrue(($dateTimeUtc['timezone'] ?? '') === 'UTC', 'get_date_time UTC: timezone field');
assertTrue(($dateTimeUtc['utc_offset'] ?? '') === '+00:00', 'get_date_time UTC: utc_offset +00:00');

$dateTimeBadTz = json_decode(($dateTimeTool->handler)(json_encode(['timezone' => 'Not/A/Zone'])), true);
assertTrue(isset($dateTimeBadTz['error']), 'get_date_time invalid timezone: error key present');

// cleanup tests/_tmp/
foreach ([$tmpFile, $patchFile, $validPhp, $invalidPhp, $rangeFile, $emptyRangeFile, $longRangeFile, $grepSample, $diffFile, $writerPlanFile, $writerArticleFile, $writerPlanFile2, $writerArticleFile2, $writerPlanFile3, $writerArticleFile3] as $relativePath) {
    try {
        $absolute = $workspace->resolve($relativePath);
        if (is_file($absolute)) {
            unlink($absolute);
        }
    } catch (WorkspaceException) {
        // already removed
    }
}
if (is_dir($tmpDir)) {
    rmdir($tmpDir);
}

echo PHP_EOL;
if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo "All smoke tests passed.\n";
exit(0);
