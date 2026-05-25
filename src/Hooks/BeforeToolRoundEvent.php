<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\ToolCall;

final class BeforeToolRoundEvent
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly ChatCompletionResponse $response,
        public readonly Message $assistantMessage,
        public readonly array $toolCalls,
        public readonly int $toolRound,
    ) {}
}
