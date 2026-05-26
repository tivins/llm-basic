<?php

namespace Tivins\LlmBasic\Skills;

use Exception;
use Tivins\LlmBasic\Skill;

/**
 * Ex:
 *
 * <code>
 *     $skill = new WriterSkill();
 *     $conversation = new Conversation();
 *     $conversation->addMessage(new Message(Role::System, $skill->prompt));
 *     $conversation->addMessage(new Message(Role::User, $skill->formatQuery([
 *         'step' => 'plan',
 *         'topic' => 'LLM inference',
 *     ])));
 *     $agent->runTurn($conversation, new ChatCompletionOptions(temperature: $skill->temperature));
 * </code>
 */
readonly class WriterSkill extends Skill
{
    public function __construct(string $name = 'writer')
    {
        parent::__construct($name, <<<TXT
You are a technical writer working in a sandbox workspace with file tools (read_file, read_file_range, write_file, append_file).

Rules:
- Always use the exact file paths given in the user message (plan_file, article_file). Do not invent other filenames.
- Always use tools to read and write files. Do not paste full file contents in chat unless summarizing what you did.
- Write clear markdown: headings, short paragraphs, and code examples when relevant.
- Keep each tool call small: at most one plan section or one article section per call (~400 words of new content max).
- Plan step: write ONLY the plan file, then stop with a brief summary. Do NOT read or write the article in this step.
- Start step: read the plan, then create the article file with write_file containing ONLY the first section from the plan.
- Continue step: read the plan and the end of the article (read_file_range), then append ONLY the next section with append_file.
- NEVER rewrite an existing article with write_file. Use append_file to extend it.
- If a tool fails, fix the issue and retry with a smaller chunk — do not fall back to rewriting the whole file.
- End each turn with a brief summary (1–2 sentences): what you wrote and what remains.
TXT,
            0.35
        );
    }

    /**
     * @throws Exception
     */
    public function formatQuery(array $parameters): string
    {
        $step = $parameters['step'] ?? null;
        if (!$step) {
            throw new Exception("Invalid parameters: 'step' is required (plan, start, or continue)");
        }

        $planFile = $parameters['plan_file'] ?? 'inference_plan.md';
        $articleFile = $parameters['article_file'] ?? 'inference_article.md';

        return match ($step) {
            'plan' => $this->formatPlanQuery($parameters, $planFile),
            'start' => $this->formatStartQuery($planFile, $articleFile),
            'continue' => $this->formatContinueQuery($planFile, $articleFile, $parameters),
            default => throw new Exception("Invalid step: {$step} (expected plan, start, or continue)"),
        };
    }

    /**
     * @throws Exception
     */
    private function formatPlanQuery(array $parameters, string $planFile): string
    {
        $topic = $parameters['topic'] ?? $parameters['query'] ?? null;
        if (!$topic) {
            throw new Exception("Invalid parameters: 'topic' is required for step plan");
        }

        return <<<TXT
step: plan
topic: {$topic}
plan_file: {$planFile}

Write a detailed plan in "{$planFile}" for a technical article about this topic.
Include section headings, a one-line goal, audience, and estimated length per section.
Do NOT write the article in this step — only the plan file, then summarize.
TXT;
    }

    private function formatStartQuery(string $planFile, string $articleFile): string
    {
        return <<<TXT
step: start
plan_file: {$planFile}
article_file: {$articleFile}

Read "{$planFile}" and write ONLY the first section of the article in "{$articleFile}" (create with write_file).
Do not write multiple sections in one tool call.
TXT;
    }

    /**
     * @throws Exception
     */
    private function formatContinueQuery(string $planFile, string $articleFile, array $parameters): string
    {
        $iteration = $parameters['iteration'] ?? null;
        $suffix = $iteration !== null ? "\niteration: {$iteration}" : '';

        return <<<TXT
step: continue
plan_file: {$planFile}
article_file: {$articleFile}{$suffix}

Read "{$planFile}" and the end of "{$articleFile}" (read_file_range), then append ONLY the next section with append_file.
Do not overwrite existing content. One section per tool call.
TXT;
    }
}
