<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\ToolCall;

final readonly class AfterToolCallEvent
{
    public function __construct(
        public ToolCall $call,
        public Message  $toolMessage,
        public int      $toolRound,
    ) {}
}
