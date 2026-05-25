<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\ToolCall;

final readonly class BeforeToolRoundEvent
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public Conversation           $conversation,
        public ChatCompletionResponse $response,
        public Message                $assistantMessage,
        public array                  $toolCalls,
        public int                    $toolRound,
    ) {}
}
