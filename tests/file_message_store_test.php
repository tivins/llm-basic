<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tivins\LlmBasic\FileMessageStore;
use Tivins\LlmBasic\Role;

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

$baseDir = sys_get_temp_dir() . '/llm-basic-file-store-' . bin2hex(random_bytes(4));
$store = new FileMessageStore($baseDir);

$messages = [
    ['id' => 1, 'role' => 'user', 'content' => 'hello', 'meta' => ['created_at' => '2026-06-04T10:00:00+00:00']],
    ['id' => 2, 'role' => 'assistant', 'content' => 'hi', 'meta' => ['created_at' => '2026-06-04T10:01:00+00:00']],
];
$store->saveMessages($messages);
$store->saveMemory("# Journal\n\n- likes coffee");

assertTrue($store->loadAllMessages() === $messages, 'load/save all messages at session root');
assertTrue($store->loadContextMessages() === $messages, 'context includes all when no watermark');
assertTrue(trim($store->loadMemory()) === "# Journal\n\n- likes coffee", 'load/save memory.md');

$archive = $store->recordCompactionEvent([['id' => 1, 'role' => 'user', 'content' => 'old']]);
assertTrue($archive['id'] !== '' && is_file((string) ($archive['path'] ?? '')), 'compaction event file created');
assertTrue($archive['from_message_id'] === 1 && $archive['to_message_id'] === 1, 'compaction event metadata');

$store->setContextFromMessageId(2);
assertTrue($store->getContextFromMessageId() === 2, 'watermark persisted');
assertTrue(count($store->loadContextMessages()) === 1, 'context filtered by watermark');

$formatted = $store->formatMessagesForSummary($messages);
assertTrue(str_contains($formatted, 'User') && str_contains($formatted, 'hello'), 'format for summary');

$convDir = $baseDir . '/conversations/thread-1';
$threadStore = new FileMessageStore($baseDir, 'thread-1');
$threadStore->saveMessages([['role' => 'user', 'content' => 'in thread']]);
assertTrue($threadStore->dataDir() === $convDir, 'conversation id uses subdirectory');
assertTrue($threadStore->loadAllMessages()[0]['content'] === 'in thread', 'isolated conversation messages');
assertTrue(isset($threadStore->loadAllMessages()[0]['id']), 'auto-assigns message id');

$badIdStore = new FileMessageStore($baseDir, 'weird/id!');
assertTrue(str_contains($badIdStore->dataDir(), 'weird_id_'), 'sanitize conversation id');

@exec('rm -rf ' . escapeshellarg($baseDir));

exit($failures === 0 ? 0 : 1);
