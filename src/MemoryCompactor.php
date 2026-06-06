<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

final class MemoryCompactor
{
    private const DEFAULT_SUMMARY_SYSTEM_PROMPT =
        'You consolidate conversational memory into concise, factual markdown journals.';

    public function __construct(
        private readonly MessageStoreInterface $store,
        private readonly int $charThreshold,
        private readonly int $keepRecentMessages,
        private readonly int $minKeepMessages,
        private readonly string $summarySystemPrompt = self::DEFAULT_SUMMARY_SYSTEM_PROMPT,
    ) {}

    public static function fromEnv(MessageStoreInterface $store): self
    {
        $threshold = (int) (getenv('CONTEXT_CHAR_THRESHOLD') ?: 24000);
        $keepRecent = (int) (getenv('KEEP_RECENT_MESSAGES') ?: 24);
        $minKeep = (int) (getenv('MIN_KEEP_MESSAGES') ?: 4);

        return new self(
            $store,
            max(4000, $threshold),
            max(4, $keepRecent),
            max(1, $minKeep),
        );
    }

    /** @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages */
    public function shouldCompact(array $messages): bool
    {
        return $this->planCompaction($messages) !== null;
    }

    /**
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{
     *   chars: int,
     *   threshold: int,
     *   percent: int,
     *   message_count: int,
     *   keep_recent_messages: int,
     *   will_keep_messages: int,
     *   ready_to_compact: bool
     * }
     */
    public function contextProgress(array $messages): array
    {
        $chars = $this->store->totalChars($messages);
        $messageCount = count($messages);
        $plan = $this->planCompaction($messages);
        $willKeep = $plan !== null ? $plan['keep'] : min($messageCount, $this->keepRecentMessages);
        $percent = $this->charThreshold > 0
            ? min(100, (int) round(($chars / $this->charThreshold) * 100))
            : 0;

        return [
            'chars' => $chars,
            'threshold' => $this->charThreshold,
            'percent' => $percent,
            'message_count' => $messageCount,
            'keep_recent_messages' => $this->keepRecentMessages,
            'will_keep_messages' => $willKeep,
            'ready_to_compact' => $plan !== null,
        ];
    }

    /**
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{
     *   compacted: bool,
     *   context_from_message_id?: int,
     *   archive_id?: string,
     *   kept: int,
     *   archived: int
     * }
     */
    public function compactIfNeeded(array $messages, LLM $llm, ChatCompletionOptions $options): array
    {
        $plan = $this->planCompaction($messages);
        if ($plan === null) {
            return ['compacted' => false, 'kept' => count($messages), 'archived' => 0];
        }

        $toArchive = $plan['toArchive'];
        $toKeep = $plan['toKeep'];

        $firstKept = $toKeep[0] ?? null;
        if ($firstKept === null || !isset($firstKept['id'])) {
            throw new \RuntimeException('Compaction requires stable message ids on kept messages');
        }
        $firstKeptId = (int) $firstKept['id'];

        $updatedMemory = $this->summarizeIntoMemory($toArchive, $llm, $options);
        $this->store->saveMemory($updatedMemory);

        $archive = $this->store->recordCompactionEvent($toArchive);
        $this->store->setContextFromMessageId($firstKeptId);

        return [
            'compacted' => true,
            'context_from_message_id' => $firstKeptId,
            'archive_id' => $archive['id'],
            'kept' => count($toKeep),
            'archived' => count($toArchive),
        ];
    }

    /**
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     * @return array{
     *   toArchive: list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}>,
     *   toKeep: list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}>,
     *   keep: int
     * }|null
     */
    private function planCompaction(array $messages): ?array
    {
        $count = count($messages);
        if ($count <= 1 || $this->store->totalChars($messages) < $this->charThreshold) {
            return null;
        }

        $keep = $this->resolveKeepCount($messages);
        $archiveCount = $count - $keep;
        if ($archiveCount <= 0) {
            return null;
        }

        return [
            'toArchive' => array_slice($messages, 0, $archiveCount),
            'toKeep' => array_slice($messages, -$keep),
            'keep' => $keep,
        ];
    }

    /**
     * @param list<array{id?: int, role: string, content: string, meta?: array<string, mixed>}> $messages
     */
    private function resolveKeepCount(array $messages): int
    {
        $count = count($messages);
        $minKeep = min($this->minKeepMessages, $count - 1);
        $maxKeep = min($this->keepRecentMessages, $count - 1);
        if ($maxKeep < $minKeep) {
            return max(1, $count - 1);
        }

        for ($keep = $maxKeep; $keep >= $minKeep; $keep--) {
            $recent = array_slice($messages, -$keep);
            if ($this->store->totalChars($recent) < $this->charThreshold) {
                return $keep;
            }
        }

        return $minKeep;
    }

    /**
     * @param list<array{role: string, content: string, meta?: array<string, mixed>}> $messagesToArchive
     */
    private function summarizeIntoMemory(array $messagesToArchive, LLM $llm, ChatCompletionOptions $options): string
    {
        $existingMemory = $this->store->loadMemory();
        $formatted = $this->store->formatMessagesForSummary($messagesToArchive);
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        $prompt = <<<PROMPT
Maintain an ongoing conversation memory journal.

Current memory (may be empty):
---
{$existingMemory}
---

Messages to merge into memory:
---
{$formatted}
---

Produce updated memory in markdown:
- Keep notable facts with dates (ISO or readable)
- Add important new elements from the archived messages
- Use a chronological journal; add a dated entry for {$today} when relevant
- Be concise but preserve important information (names, decisions, preferences, context)
- Reply ONLY with the markdown memory body, with no commentary or code fences
PROMPT;

        $conversation = new Conversation([
            new Message(Role::System, $this->summarySystemPrompt),
            new Message(Role::User, $prompt),
        ]);

        $response = $llm->chatCompletion($conversation, $options);
        $content = trim($response->firstChoice()->message->content);

        if ($content === '') {
            throw new \RuntimeException('LLM produced empty memory summary');
        }

        return self::stripMarkdownFence($content);
    }

    private static function stripMarkdownFence(string $content): string
    {
        if (preg_match('/^```(?:markdown|md)?\s*\n(.*)\n```\s*$/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }
}
