<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

final class FileMessageStore implements MessageStoreInterface
{
    private const MESSAGES_FILE = 'messages.json';
    private const MEMORY_FILE = 'memory.md';
    private const CONTEXT_FILE = 'context.json';
    private const ARCHIVES_DIR = 'archives';

    public function __construct(
        private readonly string $sessionDir,
        private readonly ?string $conversationId = null,
    ) {}

    public function loadAllMessages(): array
    {
        return $this->loadAllStoredMessages();
    }

    public function loadContextMessages(): array
    {
        $messages = $this->loadAllStoredMessages();
        $watermark = $this->getContextFromMessageId();
        if ($watermark === null) {
            return $messages;
        }

        return array_values(array_filter(
            $messages,
            static fn (array $message): bool => isset($message['id']) && (int) $message['id'] >= $watermark,
        ));
    }

    public function setContextFromMessageId(int $messageId): void
    {
        $current = $this->getContextFromMessageId();
        if ($current !== null && $messageId < $current) {
            throw new \InvalidArgumentException('context_from_message_id cannot move backward');
        }

        $dir = $this->dataDir();
        $this->ensureDir($dir);
        $json = json_encode(['context_from_message_id' => $messageId], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode context.json');
        }
        file_put_contents($dir . '/' . self::CONTEXT_FILE, $json . PHP_EOL);
    }

    public function getContextFromMessageId(): ?int
    {
        $path = $this->dataDir() . '/' . self::CONTEXT_FILE;
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['context_from_message_id'])) {
            return null;
        }

        return (int) $decoded['context_from_message_id'];
    }

    public function recordCompactionEvent(array $messages): array
    {
        $dir = $this->dataDir() . '/' . self::ARCHIVES_DIR;
        $this->ensureDir($dir);

        $ids = array_values(array_filter(
            array_map(static fn (array $message): ?int => isset($message['id']) ? (int) $message['id'] : null, $messages),
            static fn (?int $id): bool => $id !== null,
        ));

        $id = date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $path = $dir . '/' . $id . '.json';
        $payload = [
            'from_message_id' => $ids !== [] ? min($ids) : null,
            'to_message_id' => $ids !== [] ? max($ids) : null,
            'message_count' => count($messages),
            'messages' => $messages,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode compaction event');
        }
        file_put_contents($path, $json . PHP_EOL);

        return [
            'id' => $id,
            'path' => $path,
            'from_message_id' => $payload['from_message_id'],
            'to_message_id' => $payload['to_message_id'],
        ];
    }

    public function loadMessages(): array
    {
        return $this->loadContextMessages();
    }

    public function saveMessages(array $messages): void
    {
        $dir = $this->dataDir();
        $this->ensureDir($dir);
        $path = $dir . '/' . self::MESSAGES_FILE;
        $normalized = $this->normalizeMessagesWithIds($messages);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode messages.json');
        }
        file_put_contents($path, $json . PHP_EOL);
    }

    public function loadMemory(): string
    {
        $path = $this->dataDir() . '/' . self::MEMORY_FILE;
        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    public function saveMemory(string $content): void
    {
        $dir = $this->dataDir();
        $this->ensureDir($dir);
        file_put_contents($dir . '/' . self::MEMORY_FILE, $content);
    }

    public function archiveMessages(array $messages): array
    {
        $event = $this->recordCompactionEvent($messages);

        return ['id' => $event['id'], 'path' => (string) ($event['path'] ?? '')];
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
            $role = ($message['role'] ?? '') === Role::User->value ? 'User' : 'Assistant';
            $at = (string) ($message['meta']['created_at'] ?? 'unknown');
            $lines[] = "### {$role} · {$at}\n\n" . ($message['content'] ?? '');
        }

        return implode("\n\n---\n\n", $lines);
    }

    public function sessionDir(): string
    {
        return $this->sessionDir;
    }

    public function dataDir(): string
    {
        if ($this->conversationId !== null && $this->conversationId !== '') {
            return $this->sessionDir . '/conversations/' . $this->sanitizeConversationId($this->conversationId);
        }

        return $this->sessionDir;
    }

    /**
     * @return list<array{id: int, role: string, content: string, meta?: array<string, mixed>}>
     */
    private function loadAllStoredMessages(): array
    {
        $path = $this->dataDir() . '/' . self::MESSAGES_FILE;
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeMessagesWithIds($decoded);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<array{id: int, role: string, content: string, meta?: array<string, mixed>}>
     */
    private function normalizeMessagesWithIds(array $messages): array
    {
        $normalized = [];
        $nextId = 1;
        foreach ($messages as $item) {
            if (!is_array($item) || !isset($item['role'], $item['content'])) {
                continue;
            }
            $record = [
                'id' => isset($item['id']) ? (int) $item['id'] : $nextId,
                'role' => (string) $item['role'],
                'content' => (string) $item['content'],
            ];
            if (isset($item['meta']) && is_array($item['meta'])) {
                $record['meta'] = $item['meta'];
            }
            $normalized[] = $record;
            $nextId = max($nextId, $record['id'] + 1);
        }

        return $normalized;
    }

    private function sanitizeConversationId(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $id) ?? 'default';

        return $safe !== '' ? $safe : 'default';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
