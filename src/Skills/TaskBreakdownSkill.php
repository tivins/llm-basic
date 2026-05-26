<?php

namespace Tivins\LlmBasic\Skills;

use Exception;
use Tivins\LlmBasic\Skill;

/**
 * Ex:
 *
 * <code>
 *     $skill = new TaskBreakdownSkill();
 *     $options = new ChatCompletionOptions(temperature: $skill->temperature);
 *     $conversation = new Conversation();
 *     $conversation->addMessage(new Message(Role::System, $skill->prompt));
 *     $conversation->addMessage(new Message(Role::User, $skill->formatQuery([
 *         'task' => 'Create API endpoints for user authentication and authorization',
 *         'context' => 'Existing PHP codebase, no tests yet, JWT preferred',
 *         'granularity' => 'medium',
 *         'max_steps' => 8,
 *     ])));
 *     $response = $llm->chatCompletion($conversation, $options);
 *     $stored = $response->toStoredMessage($options, $response->duration ?? 0.0);
 * </code>
 */
readonly class TaskBreakdownSkill extends Skill
{
    public function __construct(string $name = 'task_breakdown')
    {
        parent::__construct($name, <<<TXT
You are a task planner. Your job is to understand the user's request, infer the main steps needed to complete it, and return an ordered, actionable breakdown.

Rules:
- Restate the goal in one short sentence under a "## Goal" heading.
- If the request is ambiguous, list your assumptions under "## Assumptions" (do not ask questions; proceed with reasonable defaults).
- Under "## Steps", produce a numbered list of 5–12 ordered steps (fewer if the task is small).
- Each step must be actionable: what to do, why it matters, and when it is done (one short "Done when:" line).
- Keep each step concise: 1–3 sentences plus the done-when line. Do not write code unless the task explicitly requires it.
- Respect any granularity, max_steps, or context constraints the user provides.
- Output ONLY the markdown structure above: no preamble, no closing remarks, no markdown fences around the whole answer.
TXT,
            0.35
        );
    }

    /**
     * @throws Exception
     */
    public function formatQuery(array $parameters): string
    {
        $task = $parameters['task'] ?? $parameters['query'] ?? null;
        if (!$task) {
            throw new Exception("Invalid parameters: 'task' is required");
        }

        $parts = ["task:\n{$task}"];

        $context = $parameters['context'] ?? null;
        if ($context) {
            $parts[] = "context:\n{$context}";
        }

        $granularity = $parameters['granularity'] ?? null;
        if ($granularity) {
            $parts[] = "granularity: {$granularity}";
        }

        $maxSteps = $parameters['max_steps'] ?? null;
        if ($maxSteps !== null) {
            $parts[] = "max_steps: {$maxSteps}";
        }

        return implode("\n\n", $parts);
    }
}