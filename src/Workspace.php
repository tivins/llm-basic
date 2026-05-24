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
}
