<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

final class Workspace
{
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
