<?php

declare(strict_types=1);

namespace Tivins\LlmBasic;

use Random\RandomException;

final class Workspace
{
    private const int MAX_READ_BYTES = 1_048_576;
    private const int MAX_WRITE_BYTES = 524_288;
    private const int DEFAULT_MAX_LIST_ENTRIES = 500;
    private const int DEFAULT_READ_RANGE_LIMIT = 200;
    private const int DEFAULT_MAX_GREP_MATCHES = 500;

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

    /**
     * @return array{
     *     file: string,
     *     content: string,
     *     start_line: int,
     *     end_line: int,
     *     total_lines: int,
     *     truncated: bool,
     * }
     */
    public function readRange(
        string $relative,
        int $offset = 1,
        int $limit = self::DEFAULT_READ_RANGE_LIMIT,
    ): array {
        if ($offset < 1) {
            throw new WorkspaceException('offset must be at least 1.');
        }

        if ($limit < 1) {
            throw new WorkspaceException('limit must be at least 1.');
        }

        $content = $this->read($relative);
        if ($content === '') {
            return [
                'file' => $this->displayPath($relative),
                'content' => '',
                'start_line' => $offset,
                'end_line' => 0,
                'total_lines' => 0,
                'truncated' => false,
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            $lines = [];
        }

        if ($content !== '' && preg_match('/\r\n|\r|\n\z/', $content) === 1) {
            array_pop($lines);
        }

        $totalLines = count($lines);

        if ($offset > $totalLines) {
            return [
                'file' => $this->displayPath($relative),
                'content' => '',
                'start_line' => $offset,
                'end_line' => $totalLines,
                'total_lines' => $totalLines,
                'truncated' => false,
            ];
        }

        $selected = array_slice($lines, $offset - 1, $limit);
        $sliceContent = implode("\n", $selected);
        $endLine = $offset + count($selected) - 1;
        $truncated = $endLine < $totalLines;

        if (strlen($sliceContent) > self::MAX_READ_BYTES) {
            throw new WorkspaceException(
                sprintf(
                    'Requested range exceeds maximum read size of %d bytes; use a smaller limit.',
                    self::MAX_READ_BYTES,
                ),
            );
        }

        return [
            'file' => $this->displayPath($relative),
            'content' => $sliceContent,
            'start_line' => $offset,
            'end_line' => $endLine,
            'total_lines' => $totalLines,
            'truncated' => $truncated,
        ];
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
     * @return array{
     *     pattern: string,
     *     path: string,
     *     matches: list<array{file: string, line: int, content: string}>,
     *     match_count: int,
     *     truncated: bool,
     * }
     */
    public function grep(
        string $pattern,
        string $relative = '',
        bool $caseInsensitive = false,
        ?string $glob = null,
        int $maxMatches = self::DEFAULT_MAX_GREP_MATCHES,
    ): array {
        if ($pattern === '') {
            throw new WorkspaceException('pattern is required.');
        }

        if ($maxMatches < 1) {
            throw new WorkspaceException('max_matches must be at least 1.');
        }

        $regex = $this->compileRegex($pattern, $caseInsensitive);
        $searchPath = $this->resolveSearchPath($relative);
        $matches = [];

        if ($searchPath['type'] === 'file') {
            $remaining = $maxMatches;
            $this->grepFile($searchPath['absolute'], $searchPath['display'], $regex, $remaining, $matches);
            $truncated = count($matches) >= $maxMatches;
        } else {
            $truncated = $this->grepDirectory(
                $searchPath['absolute'],
                $searchPath['display'],
                $regex,
                $glob,
                $maxMatches,
                $matches,
            );
        }

        return [
            'pattern' => $pattern,
            'path' => $searchPath['display'],
            'matches' => $matches,
            'match_count' => count($matches),
            'truncated' => $truncated,
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

    /**
     * @return array{absolute: string, display: string, type: 'file'|'dir'}
     */
    private function resolveSearchPath(string $relative): array
    {
        if (str_contains($relative, "\0")) {
            throw new WorkspaceException('Invalid path.');
        }

        if ($this->isAbsolute($relative)) {
            throw new WorkspaceException('Path must be relative to the workspace.');
        }

        $normalized = $this->normalizeRelative($relative);
        if ($normalized === '') {
            return [
                'absolute' => $this->root,
                'display' => '.',
                'type' => 'dir',
            ];
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . $normalized;
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new WorkspaceException("Path not found: {$relative}");
        }

        if (!$this->isInsideRoot($resolved)) {
            throw new WorkspaceException("Path escapes workspace: {$relative}");
        }

        if (is_file($resolved)) {
            return [
                'absolute' => $resolved,
                'display' => $this->displayPath($relative),
                'type' => 'file',
            ];
        }

        if (is_dir($resolved)) {
            return [
                'absolute' => $resolved,
                'display' => $this->displayPath($relative),
                'type' => 'dir',
            ];
        }

        throw new WorkspaceException("Path not found: {$relative}");
    }

    private function compileRegex(string $pattern, bool $caseInsensitive): string
    {
        $delimiter = '#';
        $escaped = str_replace($delimiter, '\\' . $delimiter, $pattern);
        $regex = $delimiter . $escaped . $delimiter;
        if ($caseInsensitive) {
            $regex .= 'i';
        }

        set_error_handler(static fn (): bool => true);
        $valid = @preg_match($regex, '') !== false;
        restore_error_handler();

        if (!$valid) {
            throw new WorkspaceException('Invalid regex pattern: ' . preg_last_error_msg());
        }

        return $regex;
    }

    /**
     * @param list<array{file: string, line: int, content: string}> $matches
     */
    private function grepFile(
        string $absolute,
        string $displayPath,
        string $regex,
        int &$remaining,
        array &$matches,
    ): void {
        if ($remaining < 1 || !is_readable($absolute)) {
            return;
        }

        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            return;
        }

        $lineNumber = 0;
        while (($line = fgets($handle)) !== false && $remaining > 0) {
            $lineNumber++;
            $content = rtrim($line, "\r\n");
            if (preg_match($regex, $content) !== 1) {
                continue;
            }

            $matches[] = [
                'file' => $displayPath,
                'line' => $lineNumber,
                'content' => $content,
            ];
            $remaining--;
        }

        fclose($handle);
    }

    /**
     * @param list<array{file: string, line: int, content: string}> $matches
     */
    private function grepDirectory(
        string $absolute,
        string $displayPrefix,
        string $regex,
        ?string $glob,
        int $maxMatches,
        array &$matches,
    ): bool {
        $names = scandir($absolute);
        if ($names === false) {
            throw new WorkspaceException('Could not list directory.');
        }

        sort($names, SORT_STRING);

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (count($matches) >= $maxMatches) {
                return true;
            }

            $full = $absolute . DIRECTORY_SEPARATOR . $name;
            $relativeName = $displayPrefix === '.' ? $name : $displayPrefix . '/' . $name;

            if (is_dir($full) && !is_link($full)) {
                if ($this->grepDirectory($full, $relativeName, $regex, $glob, $maxMatches, $matches)) {
                    return true;
                }

                continue;
            }

            if (!is_file($full) || is_link($full)) {
                continue;
            }

            if ($glob !== null && !$this->matchesGlob($relativeName, $glob)) {
                continue;
            }

            $remaining = $maxMatches - count($matches);
            $this->grepFile($full, $relativeName, $regex, $remaining, $matches);

            if (count($matches) >= $maxMatches) {
                return true;
            }
        }

        return false;
    }

    private function matchesGlob(string $relativePath, string $glob): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        $pattern = str_replace('\\', '/', $glob);

        return fnmatch($pattern, $path, FNM_PATHNAME)
            || fnmatch($pattern, basename($path));
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
