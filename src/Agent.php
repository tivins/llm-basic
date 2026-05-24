<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

use Exception;

class Agent
{
    public function __construct(
        public LLM $llm,
        public ToolRegistry $tools,
        public int $maxToolRounds = 10,
        public string $workspace = '',
    ) {}

    /**
     * @throws Exception
     */
    public function runTurn(Conversation $conversation, ChatCompletionOptions $options): AgentTurnResult
    {
        $response = $this->llm->chatCompletion($conversation, $options);
        $toolRounds = 0;

        while ($response->hasToolCalls()) {
            if ($toolRounds >= $this->maxToolRounds) {
                return new AgentTurnResult(
                    null,
                    false,
                    "Max tool rounds ({$this->maxToolRounds}) exceeded.",
                    $toolRounds,
                );
            }

            $assistant = $response->assistantMessage();
            if ($assistant === null) {
                return new AgentTurnResult(
                    null,
                    false,
                    'Assistant message missing despite tool_calls.',
                    $toolRounds,
                );
            }

            $conversation->addMessage(
                $response->toStoredMessage($options, $response->duration ?? 0.0) ?? $assistant,
            );

            foreach ($this->tools->executeAll($assistant->toolCalls ?? []) as $toolMessage) {
                $conversation->addMessage($toolMessage);
            }

            $toolRounds++;
            $response = $this->llm->chatCompletion($conversation, $options);
        }

        if ($response->finishReason() === 'stop') {
            $stored = $response->toStoredMessage($options, $response->duration ?? 0.0);
            if ($stored !== null) {
                $conversation->addMessage($stored);

                return new AgentTurnResult($stored, true, null, $toolRounds);
            }

            return new AgentTurnResult(
                null,
                false,
                'Assistant stop response could not be stored.',
                $toolRounds,
            );
        }

        $reason = $response->finishReason() ?? 'unknown';

        return new AgentTurnResult(
            null,
            false,
            "Unexpected finish reason: {$reason}.",
            $toolRounds,
        );
    }
}
