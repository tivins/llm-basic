<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\ToolCall;

final class AfterToolCallEvent
{
    public function __construct(
        public readonly ToolCall $call,
        public readonly Message $toolMessage,
        public readonly int $toolRound,
    ) {}
}
