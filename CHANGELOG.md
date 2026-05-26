# Changelog

## 0.19.3 — 2026-05-27

### Added

- `WriterSkill::articleProgress()` — compute plan/article section counts and the next expected plan section title.
- `WriterSkill` — continue/start queries accept `progress` and `last_step_summary` hints (verify against files).
- `examples/04_writer.php` — pass computed progress and the previous step summary between turns; append step summaries to `writer_journal.md`.

## 0.19.2 — 2026-05-27

### Fixed

- `Agent` — treat `finish_reason: length` as success when the response has no pending tool calls (partial text summary).
- `WriterSkill::isArticleComplete()` — count top-level `##` article headings instead of numbered `## N.` headings.
- `WriterSkill` — continue step uses `read_file_range` tail with `limit=40` and instructs the model to stop after `append_file`.
- `examples/04_writer.php` — cap continue-step tool rounds at 4 to discourage multi-section turns.

## 0.19.1 — 2026-05-27

### Removed

- `Agent::$maxCallsPerToolPerTurn` — removed after writer runs showed it blocked legitimate multi-round tool use within a single continue step (first `append_file` consumed the quota, then retries failed with a confusing error).

### Fixed

- `WriterSkill` — continue-step prompt now asks for one missing section per step without implying a hard per-turn tool cap.

## 0.19.0 — 2026-05-27

### Added

- `WriterSkill::isArticleComplete()` — compare numbered plan sections (`### N.`) with article headings (`## N.`) to detect a finished article.

### Changed

- `read_file_range` / `Workspace::readRange()` — when `offset` is omitted, return the last `limit` lines (tail mode) instead of starting at line 1.
- `WriterSkill` — prompt and continue query mention tail mode.
- `examples/04_writer.php` — stop the loop early when the article matches the plan.

## 0.18.0 — 2026-05-27

### Added

- `AppendFileTool` (`append_file`) — append UTF-8 text to the end of a workspace file without rewriting it.
- `Workspace::append()` — backing method with size limits and optional create-if-missing.

### Fixed

- `LLM::chatCompletion()` — detect HTTP errors and malformed responses (`error` payload or missing `choices`) and throw a clear exception instead of PHP warnings.
- `WriterSkill` / `examples/04_writer.php` — constrain each step (plan only, one section per tool call, exact filenames), use `append_file` for continuations, and cap `maxToolRounds` per step to avoid oversized tool-call JSON hitting the model context limit.

## 0.17.0 — 2026-05-27

### Added

- `WriterSkill` — system prompt and step queries (`plan`, `start`, `continue`) for stateless multi-turn article writing with workspace file tools.
- `examples/04_writer.php` — agent-driven writer demo: fresh conversation per step, files as persistent state (`inference_plan.md`, `inference_article.md`).

## 0.16.0 — 2026-05-26

### Added

- `ApplyDiffTool` (`apply_diff`) — apply a unified diff (`diff -u`) to any workspace file; normalises LF/CRLF/CR in both the diff and the file, restores the original EOL style, and returns a precise per-line mismatch report when a hunk fails.
- `Workspace::applyDiff()` — backing method that parses `@@ … @@` hunks, handles multi-hunk offsets, and throws `WorkspaceException` with hunk number + line-level diffs on mismatch.

## 0.15.3 — 2026-05-25

### Added

- `Invoke::listSchedulers()` — list scheduler IDs from Invoke OpenAPI (`DenoiseLatentsInvocation` schema).
- `Invoke::enqueueTextToImage()` / `Invoke::textToImage()` — optional `$scheduler` argument (default `euler`; e.g. `dpmpp_2m_k` for DPM++ 2M Karras).

## 0.15.2 — 2026-05-25

### Fixed

- `Invoke::enqueueTextToImage()` — remove redundant `save_image` node; `l2i` already persists the decoded image, so the extra node created a duplicate on Invoke.
- `Invoke::waitForBatchImage()` — resolve the batch image by queue `session_id` instead of fetching the latest global image.

## 0.15.1 — 2026-05-25

### Added

- `Invoke::deleteImage()` — delete a generated image via `DELETE /api/v1/images/i/{image_name}`.

## 0.15.0 — 2026-05-25

### Added

- `Invoke` — client for Invoke Community Edition text-to-image API (`listModels`, `fetchModel`, `enqueueTextToImage`, `waitForBatchImage`, `textToImage`, `imageUrl`).

## 0.14.1 — 2026-05-25

### Changed

- `LLM` accepts an optional `timeoutSeconds` constructor argument (default 120) instead of a hardcoded 30s cURL timeout.

## 0.14.0 — 2026-05-25

### Added

- `AgentHooks` — registry of lifecycle listeners for agent turns (`beforeTurn`, `afterTurn`, `beforeLlmCall`, `afterLlmCall`, `beforeToolRound`, `afterToolRound`, `beforeToolCall`, `afterToolCall`, `onMaxToolRoundsExceeded`).
- `AgentHookEvent` enum and typed hook payload classes under `src/Hooks/`.
- `BeforeToolCallEvent::$replacement` — skip the real tool handler and inject a custom tool message.
- Smoke tests for hook ordering, tool-call replacement, and max tool rounds.

### Changed

- `Agent` accepts an optional `AgentHooks` dependency (defaults to an empty registry).

## 0.13.0 — 2026-05-25

### Added

- `Workspace::grep()` — regex search in a workspace file or directory with optional `glob`, case-insensitive matching, and `max_matches` (default 500).
- `GrepTool` — `grep` tool returning `{pattern, path, matches[], match_count, truncated}` with `{file, line, content}` per match.
- Smoke tests for grep (single file, directory, glob, invalid regex, traversal, story fixture).

### Changed

- `test.php` registers `GrepTool` alongside workspace read/list tools.

## 0.12.0 — 2026-05-25

### Added

- `Workspace::readRange()` — line-based partial file reads with `offset` (1-based), `limit` (default 200), and `MAX_READ_BYTES` enforcement on the returned slice.
- `ReadFileRangeTool` — `read_file_range` tool returning `{file, content, start_line, end_line, total_lines, truncated}`.
- Smoke tests for `read_file_range` (line slice, tail, empty file, invalid offset, traversal).

### Changed

- `test.php` registers `ReadFileRangeTool` alongside `ReadFileTool`.

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
