<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\AgentTurnResult;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;

final readonly class AfterTurnEvent
{
    public function __construct(
        public Conversation          $conversation,
        public ChatCompletionOptions $options,
        public AgentTurnResult       $result,
    ) {}
}
