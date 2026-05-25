<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

enum AgentHookEvent: string
{
    case BeforeTurn = 'beforeTurn';
    case AfterTurn = 'afterTurn';

    case BeforeLlmCall = 'beforeLlmCall';
    case AfterLlmCall = 'afterLlmCall';

    case BeforeToolRound = 'beforeToolRound';
    case AfterToolRound = 'afterToolRound';

    case BeforeToolCall = 'beforeToolCall';
    case AfterToolCall = 'afterToolCall';

    case OnMaxToolRoundsExceeded = 'onMaxToolRoundsExceeded';
}
