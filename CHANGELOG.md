# Changelog

## 0.11.2 — 2026-05-25

### Fixed

- `WebSearchTool` — treat DuckDuckGo HTML as usable only when result anchor tags are present (not merely the `result__a` CSS class name); fall back to POST and return an error instead of a fake empty `results[]`.

## 0.11.1 — 2026-05-24

### Fixed

- `WebSearchTool` — align DuckDuckGo HTML fetch with the working `llm-php` `PredefinedTools::webSearch` approach (GET, `tivins/llm-php` user-agent, regex parsing, native CA TLS); fall back to HTML POST when GET is blocked.

## 0.11.0 — 2026-05-24

### Added

- `FetchWebPageTool` — `fetch_web_page` tool: HTTP GET with size-bounded streaming (`max_bytes`), optional HTML→plain-text extraction (`raw_html`), and structured JSON response (`url`, `http_status`, `content_type`, `truncated`, `text_extracted`, `body`).
- `WebSearchTool` — `web_search` tool: DuckDuckGo HTML scraper returning `{provider, query, results[]}` with title, url, and snippet per entry.
- `LangSearchTool` — `langsearch_web_search` tool: LangSearch API (`query`, `max_results`, `freshness`, `summary`); requires `LANGSEARCH_API_KEY` injected at construction.
- `tests/network_tools_smoke.php` — CLI smoke tests covering happy paths, error paths, and edge cases for all three tools (LangSearch tests skipped unless `LANGSEARCH_API_KEY` is set).

### Changed

- `test.php` registers `FetchWebPageTool`, `WebSearchTool`, and conditionally `LangSearchTool` (when `LANGSEARCH_API_KEY` env var is present).

## 0.10.0 — 2026-05-24

### Added

- `FileLinter` — registry of per-language syntax commands (extensible; PHP via `php -l` first).
- `Workspace::lintFile()` — sandboxed path resolution, optional language or extension auto-detect.
- `LintFileTool` — `lint_file` tool (`file`, optional `language`).
- Smoke tests for lint (valid/invalid PHP, unsupported language, traversal).

### Changed

- `test.php` registers `LintFileTool` alongside workspace tools.

## 0.9.0 — 2026-05-24

### Added

- `Workspace::applySearchReplace()` — in-memory search/replace with unique-match enforcement and `MAX_WRITE_BYTES` via `write()`.
- `ApplyPatchTool` — `apply_patch` tool (`old_string`, `new_string`, optional `replace_all` and `create_if_missing`).
- Smoke tests for apply_patch (unique replace, not found, ambiguous match).

### Changed

- `test.php` registers `ApplyPatchTool` alongside workspace write tools.

## 0.8.0 — 2026-05-24

### Added

- `Workspace::resolveForWrite()` and `Workspace::write()` — sandboxed file creation and replacement with atomic writes and `MAX_WRITE_BYTES` (512 KiB).
- `WriteFileTool` — `write_file` tool with optional `create_if_missing` and `overwrite`.
- Smoke tests for `write_file` (create, overwrite refusal, traversal) in `tests/workspace_smoke.php`.

### Changed

- `test.php` registers `WriteFileTool` alongside read/list workspace tools.

## 0.7.0 — 2026-05-24

### Added

- `Workspace::resolveDirectory()` and `Workspace::listDir()` for sandboxed directory listing.
- `ListDirTool` — `list_dir` tool with optional `recursive` and `max_entries`.
- `tests/workspace_smoke.php` — CLI smoke tests for workspace reads and directory listing.

### Changed

- `test.php` registers `ListDirTool` alongside `ReadFileTool`.

## 0.6.0 — 2026-05-24

### Added

- `Workspace` — sandboxed path resolution and file reads under a root directory.
- `WorkspaceException` for workspace policy violations.
- `ReadFileTool` uses `Workspace` instead of a raw path string.

### Changed

- `Agent::runTurn()` injects `Agent::tools` into `ChatCompletionOptions` (caller must not pass a different registry).
- `Agent::$workspace` is now `?Workspace` instead of `string`.
- `test.php` wires `Workspace`, `ReadFileTool`, and options without duplicate `tools:`.

## 0.5.0 — 2026-05-24

### Added

- `Agent::runTurn()` — multi-round tool loop until `stop` or `maxToolRounds`.
- `AgentTurnResult` — success flag, final message, error, and tool round count.

### Changed

- `test.php` delegates the agent loop to `Agent` instead of inline tool handling.

## 0.4.1 — 2026-05-23

### Changed

- `ToolRegistry::register()` accepts an optional handler `callable(string): string` for custom tool execution.

## 0.4.0 — 2026-05-23

### Added

- `Tool`, `ToolCall`, and `ToolRegistry` for OpenAI-style function tools.
- `Tool::getWeather()` as a placeholder tool definition.
- `ToolRegistry::execute()` / `executeAll()` with a fake `get_weather` implementation.
- `Role::Tool`, `Message::$toolCalls`, `Message::$toolCallId`, and `Message::toChatCompletionArray()`.
- `ChatCompletionResponse::hasToolCalls()` to detect `tool_calls` finish flows.
- `ChatCompletionOptions::$tools` now accepts a `ToolRegistry` instead of a raw array.

## 0.3.0 — 2026-05-23

### Added

- `Message::$meta` for per-message metadata (usage, timing, model, etc.).
- `Message::withCreatedAt()` helper and `Message::toArray()` / `JsonSerializable`.
- `Conversation` implements `JsonSerializable` for stable export JSON.
- `Usage::toArray()` and `ChatCompletionResponse::toStoredMessage()` to archive assistant turns with completion metadata.

## 0.2.0 — 2026-05-23

### Changed

- Renamed `Output` to `ChatCompletionResponse`; raw JSON is available via `raw()` only.
- Removed `Usage` from `Message`; usage stays on the completion response.
- Renamed `Message::$reasoning` to `reasoningContent`.
- Centralized request defaults and optional fields in `ChatCompletionOptions::toRequestArray()`.
- Added `LLM::$defaultModel` and `ChatCompletionOptions::$model` for the request body.
- Added helpers on `ChatCompletionResponse`: `firstChoice()`, `finishReason()`, `assistantMessage()`.

### Fixed

- `Role::tryFrom()` fallback now returns `Role::User` instead of the string `'User'`.
