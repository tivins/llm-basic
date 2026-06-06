<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tivins\LlmBasic\ConversationBuilder;
use Tivins\LlmBasic\MessageStoreInterface;
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

final class BuilderTestStore implements MessageStoreInterface
{
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
        return ['id' => 'x', 'path' => '/tmp/x'];
    }

    public function loadMessages(): array
    {
        return $this->loadContextMessages();
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
        return ['id' => 'x', 'path' => '/tmp/x'];
    }

    public function totalChars(array $messages): int
    {
        return 0;
    }

    public function formatMessagesForSummary(array $messages): string
    {
        return '';
    }
}

$store = new BuilderTestStore(
    [
        ['role' => 'user', 'content' => 'prior', 'meta' => ['created_at' => '2026-06-04T12:00:00+00:00']],
    ],
    "- Fact A\n- Fact B",
);

$conversation = ConversationBuilder::fromStore($store, 'You are a profile coach.');
$messages = $conversation->messages;

assertTrue(count($messages) === 2, 'system + one stored message');
$system = $messages[0];
assertTrue($system->role === Role::System, 'first message is system');
assertTrue(
    str_contains($system->content, 'You are a profile coach.'),
    'system includes app prompt',
);
assertTrue(
    str_contains($system->content, ConversationBuilder::MEMORY_SECTION_HEADING),
    'system includes memory section heading',
);
assertTrue(str_contains($system->content, 'Fact A'), 'system includes memory body');
assertTrue($messages[1]->content === 'prior', 'reloads stored user message');

$emptyMemory = ConversationBuilder::fromStore(new BuilderTestStore(), 'Base only.');
assertTrue($emptyMemory->messages[0]->content === 'Base only.', 'no memory section when empty');

$built = ConversationBuilder::buildSystemContent('App', "  \n");
assertTrue($built === 'App', 'trim empty memory');

exit($failures === 0 ? 0 : 1);
