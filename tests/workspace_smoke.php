<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tivins\LlmBasic\Tools\ListDirTool;
use Tivins\LlmBasic\Tools\ReadFileTool;
use Tivins\LlmBasic\Tools\WriteFileTool;
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

// cleanup tests/_tmp/
$tmpAbsolute = $workspace->resolve($tmpFile);
if (is_file($tmpAbsolute)) {
    unlink($tmpAbsolute);
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
