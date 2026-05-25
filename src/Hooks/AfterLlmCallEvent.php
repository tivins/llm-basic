<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Conversation;

final readonly class AfterLlmCallEvent
{
    public function __construct(
        public Conversation           $conversation,
        public ChatCompletionOptions  $options,
        public int                    $toolRound,
        public ChatCompletionResponse $response,
    ) {}
}
