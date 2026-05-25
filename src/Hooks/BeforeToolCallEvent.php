<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\ToolCall;

final class BeforeToolCallEvent
{
    /** If set, the real tool handler is skipped. */
    public ?Message $replacement = null;

    public function __construct(
        public readonly ToolCall $call,
        public readonly int $toolRound,
    ) {}
}
