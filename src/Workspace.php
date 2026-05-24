<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

use Random\RandomException;

final class Workspace
{
    private const int MAX_READ_BYTES = 1_048_576;
    private const int MAX_WRITE_BYTES = 524_288;
    private const int DEFAULT_MAX_LIST_ENTRIES = 500;

    private readonly string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new WorkspaceException("Workspace root is not a directory: {$root}");
        }

        $this->root = $resolved;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function resolve(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new WorkspaceException('File path is required.');
        }

        if ($this->isAbsolute($relative)) {
            throw new WorkspaceException('Path must be relative to the workspace.');
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $this->normalizeRelative($relative);
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new WorkspaceException("File not found: {$relative}");
        }

        if (!$this->isInsideRoot($resolved)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        return $resolved;
    }

    public function read(string $relative): string
    {
        $path = $this->resolve($relative);
        if (!is_file($path)) {
            throw new WorkspaceException("Not a file: {$relative}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new WorkspaceException("Could not read file: {$relative}");
        }

        return $content;
    }

    public function resolveForWrite(string $relative, bool $createParents = true): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new WorkspaceException('File path is required.');
        }

        if ($this->isAbsolute($relative)) {
            throw new WorkspaceException('Path must be relative to the workspace.');
        }

        $this->assertRelativeDepthWithinRoot($relative);

        $normalized = $this->normalizeRelative($relative);
        if ($normalized === '') {
            throw new WorkspaceException('File path is required.');
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $normalized;
        if (!$this->isInsideRoot($candidate)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        if (is_link($candidate)) {
            throw new WorkspaceException("Cannot write through symlink: {$relative}");
        }

        $resolved = realpath($candidate);
        if ($resolved !== false) {
            if (!$this->isInsideRoot($resolved)) {
                throw new WorkspaceException("Path escapes workspace: {$relative}");
            }

            if (is_dir($resolved)) {
                throw new WorkspaceException("Not a file: {$relative}");
            }

            return $resolved;
        }

        $parentDir = dirname($candidate);
        $parentResolved = realpath($parentDir);
        if ($parentResolved === false) {
            if (!$createParents) {
                throw new WorkspaceException("Parent directory not found: {$relative}");
            }

            $this->ensureParentDirectories($normalized);
            $parentResolved = realpath($parentDir);
            if ($parentResolved === false || !$this->isInsideRoot($parentResolved)) {
                throw new WorkspaceException("Path escapes workspace: {$relative}");
            }
        } elseif (!$this->isInsideRoot($parentResolved)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        if (!$this->isInsideRoot($candidate)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        return $candidate;
    }

    /**
     * @return array{file: string, bytes_written: int, created: bool}
     * @throws RandomException
     */
    public function write(
        string $relative,
        string $content,
        bool $createIfMissing = true,
        bool $overwrite = true,
    ): array {
        $bytes = strlen($content);
        if ($bytes > self::MAX_WRITE_BYTES) {
            throw new WorkspaceException(
                sprintf('Content exceeds maximum size of %d bytes.', self::MAX_WRITE_BYTES),
            );
        }

        $path = $this->resolveForWrite($relative);
        $exists = is_file($path);

        if ($exists && !$overwrite) {
            throw new WorkspaceException("File already exists: {$relative}");
        }

        if (!$exists && !$createIfMissing) {
            throw new WorkspaceException("File not found: {$relative}");
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new WorkspaceException("Could not write file: {$relative}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);

            throw new WorkspaceException("Could not write file: {$relative}");
        }

        return [
            'file' => $this->displayPath($relative),
            'bytes_written' => $bytes,
            'created' => !$exists,
        ];
    }

    /**
     * @return array{file: string, replacements: int, bytes_written: int}
     * @throws RandomException
     */
    public function applySearchReplace(
        string $relative,
        string $oldString,
        string $newString,
        bool $replaceAll = false,
        bool $createIfMissing = false,
    ): array {
        if ($oldString === '') {
            if ($newString === '') {
                throw new WorkspaceException('old_string is required.');
            }

            if (!$createIfMissing) {
                throw new WorkspaceException(
                    'old_string is empty; set create_if_missing to create a new file.',
                );
            }

            $writeResult = $this->write($relative, $newString, createIfMissing: true, overwrite: true);

            return [
                'file' => $writeResult['file'],
                'replacements' => 0,
                'bytes_written' => $writeResult['bytes_written'],
            ];
        }

        $content = $this->read($relative);
        $count = substr_count($content, $oldString);

        if ($count === 0) {
            throw new WorkspaceException('old_string not found in file.');
        }

        if (!$replaceAll && $count > 1) {
            throw new WorkspaceException(
                'old_string appears multiple times; use replace_all or provide a unique match.',
            );
        }

        if ($replaceAll) {
            $newContent = str_replace($oldString, $newString, $content);
            $replacements = $count;
        } else {
            $position = strpos($content, $oldString);
            if ($position === false) {
                throw new WorkspaceException('old_string not found in file.');
            }
            $newContent = substr_replace($content, $newString, $position, strlen($oldString));
            $replacements = 1;
        }

        $writeResult = $this->write($relative, $newContent, createIfMissing: true, overwrite: true);

        return [
            'file' => $writeResult['file'],
            'replacements' => $replacements,
            'bytes_written' => $writeResult['bytes_written'],
        ];
    }

    public function resolveDirectory(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw new WorkspaceException('Invalid path.');
        }

        if ($this->isAbsolute($relative)) {
            throw new WorkspaceException('Path must be relative to the workspace.');
        }

        $normalized = $this->normalizeRelative($relative);
        if ($normalized === '') {
            return $this->root;
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $normalized;
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new WorkspaceException("Directory not found: {$relative}");
        }

        if (!$this->isInsideRoot($resolved)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        if (!is_dir($resolved)) {
            throw new WorkspaceException("Not a directory: {$relative}");
        }

        return $resolved;
    }

    /**
     * @return array{
     *     file: string,
     *     language: string,
     *     valid: bool,
     *     output: string,
     *     errors: list<string>
     * }
     */
    public function lintFile(string $relative, string $language = '', ?FileLinter $linter = null): array
    {
        $path = $this->resolve($relative);
        if (!is_file($path)) {
            throw new WorkspaceException("Not a file: {$relative}");
        }

        $linter ??= new FileLinter();
        $language = trim(strtolower($language));

        if ($language === '') {
            $detected = $linter->detectLanguage($relative);
            if ($detected === null) {
                throw new WorkspaceException(
                    'Could not detect language from file extension; specify language. Supported: '
                    . implode(', ', $linter->supportedLanguages()) . '.',
                );
            }
            $language = $detected;
        } elseif (!$linter->supportsLanguage($language)) {
            throw new WorkspaceException(
                'Unsupported language: ' . $language
                . '. Supported: ' . implode(', ', $linter->supportedLanguages()) . '.',
            );
        }

        $result = $linter->lint($path, $language);

        return [
            'file' => $this->displayPath($relative),
            'language' => $language,
            'valid' => $result['valid'],
            'output' => $result['output'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * @return array{path: string, entries: list<array{name: string, type: 'file'|'dir'}>}
     */
    public function listDir(string $relative = '', bool $recursive = false, int $maxEntries = 500): array
    {
        if ($maxEntries < 1) {
            throw new WorkspaceException('max_entries must be at least 1.');
        }

        $absolute = $this->resolveDirectory($relative);
        $entries = [];

        if ($recursive) {
            $this->collectEntriesRecursive($absolute, $this->displayPath($relative), $entries, $maxEntries);
        } else {
            $this->collectEntriesFlat($absolute, $entries, $maxEntries);
        }

        return [
            'path' => $this->displayPath($relative),
            'entries' => $entries,
        ];
    }

    private function isInsideRoot(string $path): bool
    {
        return $path === $this->root
            || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
    }

    private function normalizeRelative(string $relative): string
    {
        $parts = preg_split('#[\\\\/]+#', $relative) ?: [];
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);

                continue;
            }
            $stack[] = $part;
        }

        return implode(DIRECTORY_SEPARATOR, $stack);
    }

    private function displayPath(string $relative): string
    {
        $normalized = $this->normalizeRelative($relative);

        return $normalized === '' ? '.' : str_replace(DIRECTORY_SEPARATOR, '/', $normalized);
    }

    private function assertRelativeDepthWithinRoot(string $relative): void
    {
        $parts = preg_split('#[\\\\/]+#', $relative) ?: [];
        $depth = 0;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                $depth--;
                if ($depth < 0) {
                    throw new WorkspaceException("Path escapes workspace: {$relative}");
                }

                continue;
            }
            $depth++;
        }
    }

    private function ensureParentDirectories(string $normalizedFile): void
    {
        $parentNormalized = dirname($normalizedFile);
        if ($parentNormalized === '.' || $parentNormalized === '') {
            return;
        }

        $parentCandidate = $this->root . DIRECTORY_SEPARATOR . $parentNormalized;
        if (!$this->isInsideRoot($parentCandidate)) {
            throw new WorkspaceException('Path escapes workspace.');
        }

        if (is_dir($parentCandidate)) {
            return;
        }

        if (file_exists($parentCandidate) && !is_dir($parentCandidate)) {
            throw new WorkspaceException('Path component is not a directory.');
        }

        if (!mkdir($parentCandidate, 0755, true) && !is_dir($parentCandidate)) {
            throw new WorkspaceException('Could not create parent directories.');
        }
    }

    /**
     * @param list<array{name: string, type: 'file'|'dir'}> $entries
     */
    private function collectEntriesFlat(string $absolute, array &$entries, int $maxEntries): void
    {
        $names = scandir($absolute);
        if ($names === false) {
            throw new WorkspaceException('Could not list directory.');
        }

        sort($names, SORT_STRING);

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (count($entries) >= $maxEntries) {
                break;
            }

            $full = $absolute . DIRECTORY_SEPARATOR . $name;
            $entries[] = [
                'name' => $name,
                'type' => is_dir($full) && !is_link($full) ? 'dir' : 'file',
            ];
        }
    }

    /**
     * @param list<array{name: string, type: 'file'|'dir'}> $entries
     */
    private function collectEntriesRecursive(
        string $absolute,
        string $prefix,
        array &$entries,
        int $maxEntries,
    ): void {
        $names = scandir($absolute);
        if ($names === false) {
            throw new WorkspaceException('Could not list directory.');
        }

        sort($names, SORT_STRING);

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (count($entries) >= $maxEntries) {
                return;
            }

            $full = $absolute . DIRECTORY_SEPARATOR . $name;
            $relativeName = $prefix === '.' ? $name : $prefix . '/' . $name;
            $isDir = is_dir($full) && !is_link($full);

            $entries[] = [
                'name' => $relativeName,
                'type' => $isDir ? 'dir' : 'file',
            ];

            if ($isDir) {
                $this->collectEntriesRecursive($full, $relativeName, $entries, $maxEntries);
                if (count($entries) >= $maxEntries) {
                    return;
                }
            }
        }
    }
}
