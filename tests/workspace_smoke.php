<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tivins\LlmBasic\Tools\ListDirTool;
use Tivins\LlmBasic\Tools\ReadFileTool;
use Tivins\LlmBasic\Tools\ReadFileRangeTool;
use Tivins\LlmBasic\Tools\ApplyPatchTool;
use Tivins\LlmBasic\Tools\WriteFileTool;
use Tivins\LlmBasic\Tools\LintFileTool;
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
assertTrue($rangeTail['truncated'] === false, 'readRange not truncated at end');
assertTrue($rangeTail['content'] === "line 9\nline 10", 'readRange tail content');

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

// cleanup tests/_tmp/
foreach ([$tmpFile, $patchFile, $validPhp, $invalidPhp, $rangeFile, $emptyRangeFile, $grepSample] as $relativePath) {
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
