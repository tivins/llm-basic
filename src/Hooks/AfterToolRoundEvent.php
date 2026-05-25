<?php

declare(strict_types=1);

namespace Tivins\LlmBasic\Hooks;

use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\Message;

final readonly class AfterToolRoundEvent
{
    /**
     * @param Message[] $toolMessages
     */
    public function __construct(
        public Conversation $conversation,
        public array        $toolMessages,
        public int          $toolRound,
    ) {}
}
