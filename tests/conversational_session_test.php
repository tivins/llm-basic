<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tivins\LlmBasic\Agent;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Choice;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\ConversationalSession;
use Tivins\LlmBasic\ConversationBuilder;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\MemoryCompactor;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\MessageStoreInterface;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\ToolRegistry;
use Tivins\LlmBasic\Usage;

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

final class SessionTestStore implements MessageStoreInterface
{
    public int $compactCalls = 0;
    private int $nextId = 1;

    public function __construct(private array $messages = []) {}

    public function loadAllMessages(): array
    {
        return $this->messages;
    }

    public function loadContextMessages(): array
    {
        return $this->messages;
    }

    public function setContextFromMessageId(int $messageId): void {}

    public function getContextFromMessageId(): ?int
    {
        return null;
    }

    public function recordCompactionEvent(array $messages): array
    {
        return ['id' => 'arch-1', 'path' => 'memory://arch-1'];
    }

    public function loadMessages(): array
    {
        return $this->loadContextMessages();
    }

    public function saveMessages(array $messages): void
    {
        $normalized = [];
        foreach ($messages as $message) {
            if (!isset($message['id'])) {
                $message['id'] = $this->nextId++;
            } else {
                $this->nextId = max($this->nextId, (int) $message['id'] + 1);
            }
            $normalized[] = $message;
        }
        $this->messages = $normalized;
    }

    public function loadMemory(): string
    {
        return '';
    }

    public function saveMemory(string $content): void {}

    public function archiveMessages(array $messages): array
    {
        return ['id' => 'arch-1', 'path' => 'memory://arch-1'];
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
        return '';
    }
}

final class StubLLM extends LLM
{
    public function __construct(private ChatCompletionResponse $response)
    {
        parent::__construct('stub://');
    }

    public function chatCompletion(
        Conversation $conversation,
        ChatCompletionOptions $options,
    ): ChatCompletionResponse {
        return $this->response;
    }
}

$store = new SessionTestStore();
$compactor = new MemoryCompactor($store, charThreshold: 1_000_000, keepRecentMessages: 24, minKeepMessages: 2);
$session = new ConversationalSession($store, $compactor, 'System for session test.');

$assistantResponse = new ChatCompletionResponse(
    'stub',
    new Usage(1, 1, 2),
    [new Choice(0, new Message(Role::Assistant, 'reply one'), 'stop')],
    null,
    5.0,
);
$agent = new Agent(new StubLLM($assistantResponse), new ToolRegistry());

$result = $session->runUserTurn('hello', $agent, new ChatCompletionOptions());
assertTrue($result->success, 'turn succeeds');
assertTrue(count($store->loadAllMessages()) === 2, 'persists user and assistant');
assertTrue($store->loadAllMessages()[0]['content'] === 'hello', 'stored user content');
assertTrue($store->loadAllMessages()[1]['content'] === 'reply one', 'stored assistant content');

$result2 = $session->runUserTurn('again', $agent, new ChatCompletionOptions());
assertTrue($result2->success, 'second turn succeeds');
assertTrue(count($store->loadAllMessages()) === 4, 'appends second turn');

$built = ConversationBuilder::fromStore($store, 'System for session test.');
assertTrue(count($built->messages) === 5, 'rebuild includes system + 4 stored');
assertTrue($built->messages[1]->content === 'hello', 'rebuild restores history');

$progress = $session->contextProgress();
assertTrue($progress['message_count'] === 4, 'contextProgress reflects store');
assertTrue($progress['ready_to_compact'] === false, 'no compaction at low volume');

$failAgent = new Agent(
    new StubLLM(
        new ChatCompletionResponse(
            'stub',
            new Usage(0, 0, 0),
            [new Choice(0, new Message(Role::Assistant, ''), 'content_filter')],
            null,
            0.0,
        ),
    ),
    new ToolRegistry(),
);
$beforeFail = count($store->loadAllMessages());
$failResult = $session->runUserTurn('should not save', $failAgent, new ChatCompletionOptions());
assertTrue(!$failResult->success, 'failed turn does not succeed');
assertTrue(count($store->loadAllMessages()) === $beforeFail, 'failed turn does not persist');

exit($failures === 0 ? 0 : 1);
