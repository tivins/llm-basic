<?php

namespace Tivins\LlmBasic;

class TTS
{
    public function __construct(
        private readonly string $programLocation,
        private string          $speakerName = "Gitta Nikolina",
        private bool            $forceCPU = false,
        private int             $maxChars = 350,
    )
    {
    }

    public function getSpeakers(): array
    {
        $result = shell_exec($this->programLocation . " --list-speakers");
        return array_filter(explode("\n", $result));
    }

    public function setSpeaker(string $speakerName): void
    {
        $this->speakerName = $speakerName;
    }

    public function setForceCPU(bool $forceCPU): void
    {
        $this->forceCPU = $forceCPU;
    }

    public function setMaxChars(int $maxChars): void
    {
        $this->maxChars = $maxChars;
    }

    public function toAudio(string $text_or_file, string $lang = "en", ?string $output_file = null): string
    {
        $file_wav = $output_file ?? tempnam(sys_get_temp_dir(), "tts");
        $file_txt = is_file($text_or_file) ? $text_or_file : tempnam(sys_get_temp_dir(), "tts");
        if (!is_file($text_or_file)) {
            file_put_contents($file_txt, $text_or_file);
        }

        $command = $this->programLocation
            . " -l " . escapeshellarg($lang)
            . " -s " . escapeshellarg($this->speakerName)
            . " -f " . escapeshellarg($file_txt)
            . " -o " . escapeshellarg($file_wav)
            . " --max-chars " . max(0, $this->maxChars)
            . ($this->forceCPU ? " --cpu" : "");

        shell_exec($command);

        if (!is_file($text_or_file)) {
            unlink($file_txt);
        }

        return $file_wav;
    }
}
