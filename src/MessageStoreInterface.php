<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

interface MessageStoreInterface
{
    /** @return list<array{role: string, content: string, meta?: array<string, mixed>}> */
    public function loadMessages(): array;

    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function saveMessages(array $messages): void;

    public function loadMemory(): string;

    public function saveMemory(string $content): void;

    /**
     * @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{id: string, path: string}
     */
    public function archiveMessages(array $messages): array;

    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function totalChars(array $messages): int;

    /** @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function formatMessagesForSummary(array $messages): string;
}
