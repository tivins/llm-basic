<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

/**
 * Orchestrates a multi-turn chat: load store → build context → agent turn → persist → compact.
 */
final class ConversationalSession
{
    public function __construct(
        private readonly MessageStoreInterface $store,
        private readonly MemoryCompactor $compactor,
        private readonly string $systemPrompt,
        private readonly ?Logger $logger = null,
    ) {}

    public function runUserTurn(
        string $userContent,
        Agent $agent,
        ChatCompletionOptions $options,
    ): AgentTurnResult {
        $conversation = ConversationBuilder::fromStore($this->store, $this->systemPrompt, $this->logger);
        $userMessage = Message::withCreatedAt(Role::User, $userContent);
        $conversation->addMessage($userMessage);

        $result = $agent->runTurn($conversation, $options);
        if (!$result->success || $result->message === null) {
            return $result;
        }

        $messages = $this->store->loadAllMessages();
        $messages[] = ConversationBuilder::messageToRecord($userMessage);
        $messages[] = ConversationBuilder::messageToRecord($result->message);
        $this->store->saveMessages($messages);

        $this->compactor->compactIfNeeded($this->store->loadContextMessages(), $agent->llm, $options);

        return $result;
    }

    /**
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
    public function contextProgress(): array
    {
        return $this->compactor->contextProgress($this->store->loadContextMessages());
    }

    public function store(): MessageStoreInterface
    {
        return $this->store;
    }

    public function compactor(): MemoryCompactor
    {
        return $this->compactor;
    }
}
