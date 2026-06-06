<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

/**
 * Builds a {@see Conversation} from a message store with standard long-term memory injection.
 *
 * Memory is appended to the system message under {@see MEMORY_SECTION_HEADING} so compaction
 * and turn history stay separate from durable memory.
 */
final class ConversationBuilder
{
    public const MEMORY_SECTION_HEADING = '## Long-term memory';

    public static function fromStore(
        MessageStoreInterface $store,
        string $systemPrompt,
        ?Logger $logger = null,
    ): Conversation {
        $conversation = new Conversation([], $logger);
        $conversation->addMessage(new Message(
            Role::System,
            self::buildSystemContent($systemPrompt, $store->loadMemory()),
        ));

        foreach ($store->loadContextMessages() as $record) {
            $conversation->addMessage(self::messageFromRecord($record));
        }

        return $conversation;
    }

    public static function buildSystemContent(string $systemPrompt, string $memory): string
    {
        $memory = trim($memory);
        if ($memory === '') {
            return $systemPrompt;
        }

        return rtrim($systemPrompt) . "\n\n" . self::MEMORY_SECTION_HEADING . "\n\n" . $memory;
    }

    /**
     * @param array{role: string, content: string, meta?: array<string, mixed>} $record
     */
    public static function messageFromRecord(array $record): Message
    {
        $role = Role::tryFrom($record['role']) ?? Role::Unknown;

        return new Message(
            $role,
            (string) $record['content'],
            meta: $record['meta'] ?? [],
        );
    }

    public static function messageToRecord(Message $message): array
    {
        $record = [
            'role' => $message->role->value,
            'content' => $message->content,
        ];
        if ($message->meta !== []) {
            $record['meta'] = $message->meta;
        }

        return $record;
    }
}
