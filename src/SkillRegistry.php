<?php

namespace Tivins\LlmBasic;

class SkillRegistry
{
    private array $skills = [];

    public function __construct(
        Skill ...$skills
    ) {
        $this->register(...$skills);
    }
    public function register(Skill ...$skills): self {
        foreach ($skills as $skill) {
            $this->skills[$skill->name] = $skill;
        }
    }
    public function get(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }
}