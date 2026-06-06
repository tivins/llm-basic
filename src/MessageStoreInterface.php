<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

interface MessageStoreInterface
{
    /**
     * Messages in the LLM context window (filtered by watermark when set).
     *
     * @return list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}>
     */
    public function loadContextMessages(): array;

    /**
     * All persisted messages (including those outside the LLM context window).
     *
     * @return list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}>
     */
    public function loadAllMessages(): array;

    /** Advance watermark: only messages with id >= $messageId remain in LLM context. */
    public function setContextFromMessageId(int $messageId): void;

    /** Oldest message id currently in context (null = all messages). */
    public function getContextFromMessageId(): ?int;

    /**
     * Records a compaction event for messages leaving the context window.
     *
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{id: string, path?: string, from_message_id?: int, to_message_id?: int}
     */
    public function recordCompactionEvent(array $messages): array;

    /**
     * @deprecated Use {@see loadContextMessages()} instead.
     *
     * @return list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}>
     */
    public function loadMessages(): array;

    /**
     * @deprecated Compaction no longer replaces messages; host apps should append via INSERT.
     *
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     */
    public function saveMessages(array $messages): void;

    /**
     * @deprecated Use {@see recordCompactionEvent()} instead.
     *
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{id: string, path?: string}
     */
    public function archiveMessages(array $messages): array;

    public function loadMemory(): string;

    public function saveMemory(string $content): void;

    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function totalChars(array $messages): int;

    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function formatMessagesForSummary(array $messages): string;
}
