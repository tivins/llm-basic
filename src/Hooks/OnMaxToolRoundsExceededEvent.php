<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;

final class OnMaxToolRoundsExceededEvent
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly ChatCompletionOptions $options,
        public readonly int $toolRounds,
        public readonly int $maxToolRounds,
    ) {}
}
