<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Choice;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\MemoryCompactor;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\MessageStoreInterface;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Usage;

final class InMemoryMessageStore implements MessageStoreInterface
{
    public int $saveMessagesCalls = 0;
    public int $archiveMessagesCalls = 0;
    public int $recordCompactionEventCalls = 0;

    /** @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function __construct(
        private array $messages = [],
        private string $memory = '',
        private ?int $contextFromMessageId = null,
    ) {}

    public function loadAllMessages(): array
    {
        return $this->messages;
    }

    public function loadContextMessages(): array
    {
        if ($this->contextFromMessageId === null) {
            return $this->messages;
        }

        $watermark = $this->contextFromMessageId;

        return array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => isset($message['id']) && (int) $message['id'] >= $watermark,
        ));
    }

    public function setContextFromMessageId(int $messageId): void
    {
        if ($this->contextFromMessageId !== null && $messageId < $this->contextFromMessageId) {
            throw new \InvalidArgumentException('context_from_message_id cannot move backward');
        }
        $this->contextFromMessageId = $messageId;
    }

    public function getContextFromMessageId(): ?int
    {
        return $this->contextFromMessageId;
    }

    public function recordCompactionEvent(array $messages): array
    {
        $this->recordCompactionEventCalls++;
        $ids = array_map(static fn (array $message): int => (int) $message['id'], $messages);

        return [
            'id' => 'event-' . $this->recordCompactionEventCalls,
            'from_message_id' => min($ids),
            'to_message_id' => max($ids),
        ];
    }

    public function loadMessages(): array
    {
        return $this->loadContextMessages();
    }

    public function saveMessages(array $messages): void
    {
        $this->saveMessagesCalls++;
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
        $this->archiveMessagesCalls++;

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

final class StubSummaryLLM extends LLM
{
    public function __construct(private string $summary = '# Memory journal')
    {
        parent::__construct('stub://');
    }

    public function chatCompletion(
        Conversation $conversation,
        ChatCompletionOptions $options,
    ): ChatCompletionResponse {
        return new ChatCompletionResponse(
            'stub',
            new Usage(1, 1, 2),
            [new Choice(0, new Message(Role::Assistant, $this->summary), 'stop')],
            null,
            0.0,
        );
    }
}

$store = new InMemoryMessageStore();
$compactor = new MemoryCompactor($store, charThreshold: 100, keepRecentMessages: 24, minKeepMessages: 2);

$fewLong = [
    ['id' => 1, 'role' => 'user', 'content' => str_repeat('x', 80), 'meta' => ['created_at' => '2026-05-28T10:00:00+00:00']],
    ['id' => 2, 'role' => 'assistant', 'content' => 'ok', 'meta' => ['created_at' => '2026-05-28T10:01:00+00:00']],
    ['id' => 3, 'role' => 'user', 'content' => 'recent', 'meta' => ['created_at' => '2026-05-28T10:02:00+00:00']],
];
assert($compactor->shouldCompact($fewLong), 'compact when chars exceed threshold');

$many = [];
for ($i = 0; $i < 24; $i++) {
    $many[] = [
        'id' => $i + 1,
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

$small = [['id' => 1, 'role' => 'user', 'content' => 'hi', 'meta' => []]];
assert(!$compactor->shouldCompact($small));

$compactStore = new InMemoryMessageStore($fewLong);
$compactCompactor = new MemoryCompactor($compactStore, charThreshold: 100, keepRecentMessages: 24, minKeepMessages: 2);
$beforeCount = count($compactStore->loadAllMessages());
$result = $compactCompactor->compactIfNeeded(
    $compactStore->loadContextMessages(),
    new StubSummaryLLM('- archived fact'),
    new ChatCompletionOptions(),
);

assert($result['compacted'] === true, 'compaction runs');
assert($result['context_from_message_id'] === 3, 'watermark set to first kept message id');
assert($result['archived'] === 2, 'two messages archived from context');
assert($result['kept'] === 1, 'one message kept in context');
assert($compactStore->saveMessagesCalls === 0, 'compact does not call saveMessages');
assert($compactStore->archiveMessagesCalls === 0, 'compact does not call archiveMessages');
assert($compactStore->recordCompactionEventCalls === 1, 'compact records compaction event');
assert(count($compactStore->loadAllMessages()) === $beforeCount, 'all messages remain persisted');
assert($compactStore->getContextFromMessageId() === 3, 'watermark persisted on store');
assert(count($compactStore->loadContextMessages()) === 1, 'context window filtered after compact');
assert(trim($compactStore->loadMemory()) === '- archived fact', 'memory updated from LLM summary');

echo "OK\n";
