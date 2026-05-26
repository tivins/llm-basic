<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Tivins\LlmBasic\Agent;
use Tivins\LlmBasic\AgentHookEvent;
use Tivins\LlmBasic\AgentHooks;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\ChatCompletionResponse;
use Tivins\LlmBasic\Choice;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\Hooks\BeforeToolCallEvent;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolCall;
use Tivins\LlmBasic\ToolRegistry;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Usage;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        echo "FAIL: {$message}\n";
        $failures++;
    } else {
        echo "OK: {$message}\n";
    }
}

final class StubLLM extends LLM
{
    /** @param list<ChatCompletionResponse> $responses */
    public function __construct(private array $responses)
    {
        parent::__construct('stub://');
    }

    private int $callIndex = 0;

    public function chatCompletion(
        Conversation $conversation,
        ChatCompletionOptions $options,
    ): ChatCompletionResponse {
        if (!isset($this->responses[$this->callIndex])) {
            throw new RuntimeException('StubLLM: no more canned responses');
        }

        return $this->responses[$this->callIndex++];
    }
}

function makeResponse(Message $message, string $finishReason = 'stop'): ChatCompletionResponse
{
    return new ChatCompletionResponse(
        'stub-model',
        new Usage(1, 1, 2),
        [new Choice(0, $message, $finishReason)],
        null,
        1.0,
    );
}

$echoTool = new Tool(
    new ToolSchema(
        'echo',
        'Echo input back.',
        [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
            ],
            'required' => ['text'],
        ],
    ),
    fn (string $argumentsJson): string => $argumentsJson,
);

$tools = new ToolRegistry($echoTool);

$toolCallResponse = makeResponse(
    new Message(
        Role::Assistant,
        '',
        toolCalls: [
            new ToolCall('call-1', 'echo', '{"text":"hi"}'),
        ],
    ),
    'tool_calls',
);

$stopResponse = makeResponse(
    new Message(Role::Assistant, 'done'),
    'stop',
);

$stubLlm = new StubLLM([$toolCallResponse, $stopResponse]);

$events = [];
$hooks = (new AgentHooks())
    ->beforeTurn(function () use (&$events): void { $events[] = AgentHookEvent::BeforeTurn->value; })
    ->beforeLlmCall(function () use (&$events): void { $events[] = AgentHookEvent::BeforeLlmCall->value; })
    ->afterLlmCall(function () use (&$events): void { $events[] = AgentHookEvent::AfterLlmCall->value; })
    ->beforeToolRound(function () use (&$events): void { $events[] = AgentHookEvent::BeforeToolRound->value; })
    ->beforeToolCall(function () use (&$events): void { $events[] = AgentHookEvent::BeforeToolCall->value; })
    ->afterToolCall(function () use (&$events): void { $events[] = AgentHookEvent::AfterToolCall->value; })
    ->afterToolRound(function () use (&$events): void { $events[] = AgentHookEvent::AfterToolRound->value; })
    ->afterTurn(function () use (&$events): void { $events[] = AgentHookEvent::AfterTurn->value; });

$agent = new Agent($stubLlm, $tools, maxToolRounds: 5, hooks: $hooks);
$conversation = new Conversation([]);
$result = $agent->runTurn($conversation, new ChatCompletionOptions());

assertTrue($result->success, 'turn succeeds');
assertTrue($result->toolRounds === 1, 'one tool round');
assertTrue(
    $events === [
        'beforeTurn',
        'beforeLlmCall',
        'afterLlmCall',
        'beforeToolRound',
        'beforeToolCall',
        'afterToolCall',
        'afterToolRound',
        'beforeLlmCall',
        'afterLlmCall',
        'afterTurn',
    ],
    'hooks fire in order',
);

$replacementResponse = makeResponse(
    new Message(
        Role::Assistant,
        '',
        toolCalls: [
            new ToolCall('call-2', 'echo', '{"text":"blocked"}'),
        ],
    ),
    'tool_calls',
);
$stopResponse2 = makeResponse(new Message(Role::Assistant, 'ok'), 'stop');

$replacementEvents = [];
$replacementHooks = (new AgentHooks())
    ->beforeToolCall(function (BeforeToolCallEvent $event) use (&$replacementEvents): void {
        $replacementEvents[] = $event->call->name;
        $event->replacement = new Message(
            Role::Tool,
            '{"blocked":true}',
            toolCallId: $event->call->id,
        );
    });

$replacementAgent = new Agent(
    new StubLLM([$replacementResponse, $stopResponse2]),
    $tools,
    hooks: $replacementHooks,
);
$replacementConversation = new Conversation([]);
$replacementResult = $replacementAgent->runTurn($replacementConversation, new ChatCompletionOptions());

assertTrue($replacementResult->success, 'replacement turn succeeds');
assertTrue($replacementEvents === ['echo'], 'beforeToolCall runs for echo');
$toolMessage = $replacementConversation->messages[1] ?? null;
assertTrue(
    $toolMessage instanceof Message && $toolMessage->content === '{"blocked":true}',
    'beforeToolCall replacement is used',
);

$maxRoundResponse = makeResponse(
    new Message(
        Role::Assistant,
        '',
        toolCalls: [new ToolCall('call-loop', 'echo', '{}')],
    ),
    'tool_calls',
);

$maxEvents = [];
$maxHooks = (new AgentHooks())
    ->onMaxToolRoundsExceeded(function () use (&$maxEvents): void { $maxEvents[] = 'max'; });

$maxAgent = new Agent(
    new StubLLM(array_fill(0, 3, $maxRoundResponse)),
    $tools,
    maxToolRounds: 2,
    hooks: $maxHooks,
);
$maxResult = $maxAgent->runTurn(new Conversation([]), new ChatCompletionOptions());

assertTrue(!$maxResult->success, 'max tool rounds fails turn');
assertTrue($maxResult->toolRounds === 2, 'max tool rounds count');
assertTrue($maxEvents === ['max'], 'onMaxToolRoundsExceeded fires');

$lengthResponse = makeResponse(
    new Message(Role::Assistant, 'partial summary'),
    'length',
);
$lengthAgent = new Agent(new StubLLM([$lengthResponse]), $tools);
$lengthResult = $lengthAgent->runTurn(new Conversation([]), new ChatCompletionOptions());

assertTrue($lengthResult->success, 'length finish without tool calls succeeds');
assertTrue($lengthResult->message?->content === 'partial summary', 'length finish stores assistant content');

exit($failures === 0 ? 0 : 1);
