<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tivins\LlmBasic\MemoryCompactor;
use Tivins\LlmBasic\MessageStoreInterface;
use Tivins\LlmBasic\Role;

final class InMemoryMessageStore implements MessageStoreInterface
{
    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function __construct(
        private array $messages = [],
        private string $memory = '',
    ) {}

    public function loadMessages(): array
    {
        return $this->messages;
    }

    public function saveMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function loadMemory(): string
    {
        return $this->memory;
    }

    public function saveMemory(string $content): void
    {
        $this->memory = $content;
    }

    public function archiveMessages(array $messages): array
    {
        return ['id' => 'test-archive', 'path' => 'memory://test-archive'];
    }

    public function totalChars(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += mb_strlen((string) ($message['content'] ?? ''));
        }

        return $total;
    }

    public function formatMessagesForSummary(array $messages): string
    {
        $lines = [];
        foreach ($messages as $message) {
            $role = $message['role'] === Role::User->value ? 'User' : 'Assistant';
            $at = (string) ($message['meta']['created_at'] ?? 'unknown');
            $lines[] = "### {$role} · {$at}\n\n" . $message['content'];
        }

        return implode("\n\n---\n\n", $lines);
    }
}

$store = new InMemoryMessageStore();
$compactor = new MemoryCompactor($store, charThreshold: 100, keepRecentMessages: 24, minKeepMessages: 2);

$fewLong = [
    ['role' => 'user', 'content' => str_repeat('x', 80), 'meta' => ['created_at' => '2026-05-28T10:00:00+00:00']],
    ['role' => 'assistant', 'content' => 'ok', 'meta' => ['created_at' => '2026-05-28T10:01:00+00:00']],
    ['role' => 'user', 'content' => 'recent', 'meta' => ['created_at' => '2026-05-28T10:02:00+00:00']],
];
assert($compactor->shouldCompact($fewLong), 'compact when chars exceed threshold');

$many = [];
for ($i = 0; $i < 24; $i++) {
    $many[] = [
        'role' => $i % 2 === 0 ? 'user' : 'assistant',
        'content' => str_repeat('a', 5),
        'meta' => ['created_at' => '2026-05-28T10:00:00+00:00'],
    ];
}
assert($store->totalChars($many) >= 100);
assert($compactor->shouldCompact($many));

$planProgress = $compactor->contextProgress($many);
assert($planProgress['ready_to_compact'] === true);
assert($planProgress['will_keep_messages'] < 24);

$small = [['role' => 'user', 'content' => 'hi', 'meta' => []]];
assert(!$compactor->shouldCompact($small));

echo "OK\n";
