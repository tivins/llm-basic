<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use Tivins\LlmBasic\Agent;
use Tivins\LlmBasic\ChatCompletionOptions;
use Tivins\LlmBasic\Conversation;
use Tivins\LlmBasic\LLM;
use Tivins\LlmBasic\Message;
use Tivins\LlmBasic\Role;
use Tivins\LlmBasic\ToolSchema;
use Tivins\LlmBasic\Tool;
use Tivins\LlmBasic\ToolRegistry;
use Tivins\LlmBasic\Logger;
use Tivins\LlmBasic\Tools\ListDirTool;
use Tivins\LlmBasic\Tools\ReadFileTool;
use Tivins\LlmBasic\Tools\ReadFileRangeTool;
use Tivins\LlmBasic\Tools\ApplyPatchTool;
use Tivins\LlmBasic\Tools\WriteFileTool;
use Tivins\LlmBasic\Tools\LintFileTool;
use Tivins\LlmBasic\Tools\FetchWebPageTool;
use Tivins\LlmBasic\Tools\WebSearchTool;
use Tivins\LlmBasic\Tools\LangSearchTool;
use Tivins\LlmBasic\Tools\GrepTool;
use Tivins\LlmBasic\Workspace;

function getCityPopulation(): Tool
{
    return new Tool(
        new ToolSchema(
            'get_city_population',
            'Get the population of a city.',
            [
                'type' => 'object',
                'properties' => [
                    'city' => [
                        'type' => 'string',
                        'description' => 'City name, e.g. Paris',
                    ],
                ],
                'required' => ['city'],
            ],
        ),
        function (string $argumentsJson): string {
            $args = json_decode($argumentsJson, true) ?? [];
            $city = $args['city'] ?? 'unknown';

            return json_encode([
                'city' => $city,
                'population' => match (strtolower($city)) {
                    'paris' => 2_161_000,
                    'lyon' => 516_000,
                    default => 100_000,
                },
                'source' => 'fake',
            ], JSON_UNESCAPED_UNICODE);
        }
    );
}

function getCityWeather(): Tool
{
    return new Tool(
        new ToolSchema(
            'get_city_weather',
            'Get the weather of a city.',
            [
                'type' => 'object',
                'properties' => [
                    'city' => [
                        'type' => 'string',
                        'description' => 'City name, e.g. Paris',
                    ],
                ],
                'required' => ['city'],
            ],
        ),
        function (string $argumentsJson): string {
            $args = json_decode($argumentsJson, true) ?? [];
            $city = $args['city'] ?? 'unknown';

            return json_encode([
                'city' => $city,
                'temperature' => match (strtolower($city)) {
                    'paris' => 22,
                    'lyon' => 18,
                    default => 15,
                },
                'unit' => 'celsius',
                'condition' => match (strtolower($city)) {
                    'paris' => 'sunny',
                    'lyon' => 'cloudy',
                    default => 'rainy',
                },
                'source' => 'fake',
            ], JSON_UNESCAPED_UNICODE);
        }
    );
}

try {
    date_default_timezone_set('Europe/Paris');
    $logger = new Logger(__dir__ . '/logs/chat-' . date('Y-m-d-H-i-s-Z') . '.json');
    $workspace = new Workspace(__DIR__ . '/tmp');
    $langSearchKey = getenv('LANGSEARCH_API_KEY');

    $tools = new ToolRegistry(
        getCityPopulation(),
        getCityWeather(),
        new ReadFileTool($workspace),
        new ReadFileRangeTool($workspace),
        new ListDirTool($workspace),
        new GrepTool($workspace),
        new WriteFileTool($workspace),
        new ApplyPatchTool($workspace),
        new LintFileTool($workspace),
        new FetchWebPageTool(),
        new WebSearchTool(),
        ...($langSearchKey !== false && $langSearchKey !== ''
            ? [new LangSearchTool($langSearchKey)]
            : []),
    );

    $llm = new LLM('http://127.0.0.1:8080');
    $options = new ChatCompletionOptions();
    $agent = new Agent($llm, $tools, maxToolRounds: 20, workspace: $workspace);
    $conversation = new Conversation([
        Message::withCreatedAt(Role::System, 'You are a helpful assistant. Use tools when needed.'),
    ], $logger);

    while (true) {
        echo "\e[32mYou> ";
        $ask = trim(fread(STDIN, 8192) ?: '');
        echo "\e[0m";
        if ($ask === '' || $ask === 'q') {
            break;
        }
        $conversation->addMessage(Message::withCreatedAt(Role::User, $ask));

        $result = $agent->runTurn($conversation, $options);
        if ($result->success && $result->message !== null) {
            echo "\e[34m" . $result->message->content . "\e[0m" . PHP_EOL;
        } elseif ($result->error !== null) {
            fwrite(STDERR, $result->error . PHP_EOL);
        }
    }
    // echo json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . $e->getMessage() . PHP_EOL);
    exit(1);
}
