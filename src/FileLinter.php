<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

/**
 * Runs sandbox-safe syntax checks via fixed per-language commands.
 *
 * @phpstan-type LanguageSpec array{extensions: list<string>, command: list<string>}
 */
final class FileLinter
{
    private const int TIMEOUT_SECONDS = 10;

    /** @var array<string, LanguageSpec> */
    private const array LANGUAGES = [
        'php' => [
            'extensions' => ['php'],
            'command' => ['php', '-l'],
        ],
    ];

    /**
     * @return list<string>
     */
    public function supportedLanguages(): array
    {
        $languages = array_keys(self::LANGUAGES);
        sort($languages);

        return $languages;
    }

    public function detectLanguage(string $relativePath): ?string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        foreach (self::LANGUAGES as $language => $spec) {
            if (in_array($extension, $spec['extensions'], true)) {
                return $language;
            }
        }

        return null;
    }

    public function supportsLanguage(string $language): bool
    {
        return isset(self::LANGUAGES[strtolower($language)]);
    }

    /**
     * @return array{valid: bool, output: string, errors: list<string>}
     */
    public function lint(string $absolutePath, string $language): array
    {
        $language = strtolower($language);
        $spec = self::LANGUAGES[$language] ?? null;
        if ($spec === null) {
            throw new WorkspaceException(
                'Unsupported language: ' . $language
                . '. Supported: ' . implode(', ', $this->supportedLanguages()) . '.',
            );
        }

        if (!is_file($absolutePath)) {
            throw new WorkspaceException('Not a file.');
        }

        $command = [...$spec['command'], $absolutePath];
        $run = $this->runCommand($command);
        $output = trim($run['output']);
        $valid = $run['exit_code'] === 0;

        return [
            'valid' => $valid,
            'output' => $output,
            'errors' => $valid ? [] : $this->splitErrors($output),
        ];
    }

    /**
     * @param list<string> $command
     * @return array{exit_code: int, output: string}
     */
    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new WorkspaceException('Could not run linter (process failed to start).');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                proc_close($process);

                throw new WorkspaceException(
                    sprintf('Linter timed out after %d seconds.', self::TIMEOUT_SECONDS),
                );
            }

            usleep(50_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $status['exitcode'] ?? 1;
        }

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout . ($stderr !== '' ? ($stdout !== '' ? "\n" : '') . $stderr : '')),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitErrors(string $output): array
    {
        if ($output === '') {
            return ['Syntax check failed.'];
        }

        $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];
        $errors = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $errors[] = $line;
            }
        }

        return $errors !== [] ? $errors : ['Syntax check failed.'];
    }
}
