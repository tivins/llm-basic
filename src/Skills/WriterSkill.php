<?php

namespace Tivins\LlmBasic\Skills;

use Exception;
use Tivins\LlmBasic\Skill;
use Tivins\LlmBasic\Workspace;
use Tivins\LlmBasic\WorkspaceException;

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
- Continue step: read the plan, then read the article tail with read_file_range (omit offset, limit=40), append ONLY the next missing section with append_file, then reply with a summary — no further tool calls after append_file.
- When progress or previous_step_reported appear in the user message, treat them as hints only — always verify against the files before writing.
- Do not append multiple plan sections in one continue step.
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
            'start' => $this->formatStartQuery($planFile, $articleFile, $parameters),
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

    private function formatStartQuery(string $planFile, string $articleFile, array $parameters): string
    {
        $contextBlock = $this->formatStepContext($parameters);

        return <<<TXT
step: start
plan_file: {$planFile}
article_file: {$articleFile}
{$contextBlock}
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
        $contextBlock = $this->formatStepContext($parameters);

        return <<<TXT
step: continue
plan_file: {$planFile}
article_file: {$articleFile}{$suffix}
{$contextBlock}
Read "{$planFile}", then read the end of "{$articleFile}" with read_file_range (omit offset, limit=40), then append ONLY the next section with append_file.
After append_file succeeds, respond with a brief summary — do not call any more tools in this turn.
Do not overwrite existing content. One section per turn; if all plan sections are already present, say so and stop.
TXT;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function formatStepContext(array $parameters): string
    {
        $lines = [];

        if (isset($parameters['progress']) && is_array($parameters['progress'])) {
            /** @var array<string, mixed> $progress */
            $progress = $parameters['progress'];
            $planSections = (int) ($progress['plan_sections'] ?? 0);
            $articleSections = (int) ($progress['article_sections'] ?? 0);
            $lines[] = "progress: {$articleSections}/{$planSections} plan sections written in the article.";

            $nextPlanSection = $progress['next_plan_section'] ?? null;
            if (is_string($nextPlanSection) && $nextPlanSection !== '') {
                $nextNumber = $articleSections + 1;
                $lines[] = "next_plan_section: \"### {$nextNumber}. {$nextPlanSection}\" (hint — verify against files).";
            } elseif ($progress['complete'] ?? false) {
                $lines[] = 'progress_note: all plan sections appear present — verify and stop if complete.';
            }
        }

        $lastSummary = $parameters['last_step_summary'] ?? null;
        if (is_string($lastSummary) && trim($lastSummary) !== '') {
            $lines[] = 'previous_step_reported: "' . $this->collapseForPrompt($lastSummary) . '" (hint only — verify against files).';
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{plan_sections: int, article_sections: int, complete: bool, next_plan_section: ?string}|null
     */
    public function articleProgress(Workspace $workspace, string $planFile, string $articleFile): ?array
    {
        try {
            $planContent = $workspace->read($planFile);
            $articleContent = $workspace->read($articleFile);
        } catch (WorkspaceException) {
            return null;
        }

        $planSections = $this->countNumberedSections($planContent, '/^### \d+\./m');
        $articleSections = $this->countNumberedSections($articleContent, '/^## /m');
        $nextPlanSection = null;
        if ($planSections > $articleSections) {
            $nextPlanSection = $this->extractPlanSectionTitle($planContent, $articleSections + 1);
        }

        return [
            'plan_sections' => $planSections,
            'article_sections' => $articleSections,
            'complete' => $planSections > 0 && $articleSections >= $planSections,
            'next_plan_section' => $nextPlanSection,
        ];
    }

    public function isArticleComplete(Workspace $workspace, string $planFile, string $articleFile): bool
    {
        $progress = $this->articleProgress($workspace, $planFile, $articleFile);

        return $progress !== null && $progress['complete'];
    }

    private function extractPlanSectionTitle(string $planContent, int $sectionNumber): ?string
    {
        if (!preg_match('/^### ' . $sectionNumber . '\. (.+)$/m', $planContent, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function collapseForPrompt(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($text));

        if (!is_string($collapsed)) {
            return trim($text);
        }

        if (strlen($collapsed) <= 500) {
            return $collapsed;
        }

        return substr($collapsed, 0, 497) . '...';
    }

    private function countNumberedSections(string $content, string $pattern): int
    {
        $count = preg_match_all($pattern, $content);

        return $count === false ? 0 : $count;
    }
}
