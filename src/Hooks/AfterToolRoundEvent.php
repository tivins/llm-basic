<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\Message;

final class AfterToolRoundEvent
{
    /**
     * @param Message[] $toolMessages
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $toolMessages,
        public readonly int $toolRound,
    ) {}
}
