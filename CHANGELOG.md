# Changelog

## 0.22.0 — 2026-06-06

### Added

- `FileMessageStore` — reference `MessageStoreInterface` implementation (`messages.json`, `memory.md`, `archives/`, `context.json`) with optional per-conversation subdirectory and stable message `id`s in JSON.
- `ConversationBuilder` — builds a `Conversation` from a store; injects `loadMemory()` into the system message under `## Long-term memory`.
- `ConversationalSession` — orchestrates a user turn (build context → `Agent::runTurn()` → persist user/assistant → `MemoryCompactor::compactIfNeeded()`); exposes `contextProgress()` for UI.
- `examples/06_profile_conversation.php` — stdin loop for profile-discovery chat with file-backed memory.
- `tests/file_message_store_test.php`, `tests/conversation_builder_test.php`, `tests/conversational_session_test.php` — smoke tests without live LLM compaction.

### Changed

- `MessageStoreInterface` — watermark API: `loadAllMessages()`, `loadContextMessages()`, `setContextFromMessageId()`, `getContextFromMessageId()`, `recordCompactionEvent()`. `loadMessages()`, `saveMessages()`, `archiveMessages()` kept but `@deprecated`.
- `MemoryCompactor::compactIfNeeded()` — advances the context watermark instead of calling `saveMessages()` / `archiveMessages()`; returns `context_from_message_id`. Requires stable `id` on kept messages. Order: summarize → `saveMemory` → `recordCompactionEvent` → `setContextFromMessageId`.
- `ConversationBuilder` / `ConversationalSession` — use `loadContextMessages()` for LLM context and `loadAllMessages()` for persistence.
- `tests/memory_compactor_test.php` — verifies compaction does not call `saveMessages` and advances watermark.

## 0.21.0 — 2026-06-04

### Added

- `MessageStoreInterface` — abstraction for conversation message/memory persistence used by compaction.
- `MemoryCompactor` — archives older messages when a character threshold is exceeded, merges a summary into long-term memory via `LLM::chatCompletion()`, and keeps a configurable recent window (`CONTEXT_CHAR_THRESHOLD`, `KEEP_RECENT_MESSAGES`, `MIN_KEEP_MESSAGES` env vars in `fromEnv()`).
- Injectable `$summarySystemPrompt` on `MemoryCompactor` (generic default; host apps can pass bot-specific instructions).
- `tests/memory_compactor_test.php` — compaction planning smoke test with an in-memory store.

## 0.19.9 — 2026-05-29

### Added

- `GetDateTimeTool` — `get_date_time` tool: host clock as ISO-8601 datetime with optional IANA `timezone` (`date`, `time`, `day_of_week`, `unix_timestamp`, `utc_offset`).
- `tests/workspace_smoke.php` — smoke tests for `GetDateTimeTool`.
- `test.php` registers `GetDateTimeTool`.

## 0.19.8 — 2026-05-29

### Added

- `OpenMeteoTool` — `open_meteo_forecast` tool: free weather forecast via [Open-Meteo](https://open-meteo.com/en/docs) (`latitude`, `longitude`, optional `current` / `hourly` / `daily` variable lists, `timezone`, `forecast_days`, `past_days`, `models`).
- `tests/network_tools_smoke.php` — smoke tests for `OpenMeteoTool` (Berlin example, validation errors).
- `test.php` registers `OpenMeteoTool`.

## 0.19.7 — 2026-05-27

### Added

- `Invoke::enqueueTextToImage()` — new optional `$loras` parameter: accepts a list of resolved LoRA model refs (from `listModels('lora')`) each augmented with a `weight` float key. When provided, builds a `lora_selector` → `collect` → `lora_collection_loader` (`sdxl_lora_collection_loader` for SDXL) subgraph that mirrors the Invoke UI workflow, replacing the model_loader as the unet/clip/clip2 source.
- `Invoke::textToImage()` — new optional `$loras` parameter: accepts `[['name' => string, 'weight' => float], ...]`; each name is resolved via `listModels('lora')` before being passed to `enqueueTextToImage()`.
- `Invoke::fetchQueueItemError()` — private helper that fetches the failed queue item and extracts structured node-level error messages from `session.execution_graph.nodes`; used by `waitForBatchImage()` to emit actionable error details instead of the opaque batch status blob.

## 0.19.6 — 2026-05-27

### Changed

- `Invoke::enqueueTextToImage()` / `Invoke::textToImage()` — add optional `$vaeModel` parameter: when provided for SDXL, a dedicated `vae_loader` node replaces the checkpoint's built-in VAE (recommended: `sdxl-vae-fp16-fix` for better colour fidelity).
- `Invoke::enqueueTextToImage()` — `l2i` node now sets `fp32 = true` for more stable FP16 VAE decoding.
- `Invoke::enqueueTextToImage()` — adaptive SDXL defaults: scheduler falls back to `dpmpp_2m_sde_k` and `cfg_scale` to `5.0` when the caller passes the SD 1.5 defaults (`euler` / `7.5`) and the model is SDXL. Explicit values are always respected.
- `Invoke::enqueueTextToImage()` — SDXL negative prompt now propagates to the `style` field of `sdxl_compel_prompt` (was empty string), strengthening the second text-encoder negative conditioning.

## 0.19.5 — 2026-05-27

### Added

- `examples/05_translate_article.php` — chunk-based EN→FR markdown translator for large files.
  Splits the document at H1–H3 heading boundaries (falling back to paragraph-level splitting
  for oversized sections) so that every `chatCompletion()` call stays well within
  `--ctx-size 16384`. Translated chunks are appended to the output file as they are produced,
  giving real-time progress and allowing partial recovery on interruption.
- `stripPreamble()` helper in `05_translate_article.php` — detects and removes chain-of-thought
  or annotation text that some models emit before the translated markdown despite the system
  prompt forbidding it. Anchors on the first heading of the expected level; emits a STDERR
  warning when a preamble is stripped.

## 0.19.4 — 2026-05-27

### Fixed

- `ReadFileRangeTool` — detect and reject parameter keys containing unexpected names (catches malformed args like `"limit=10,offset"` where the model concatenated multiple param names into a single key); return a clear error instead of silently falling back to reading the whole file.
- `ReadFileRangeTool` — lower `DEFAULT_LIMIT` from 200 to 60 and cap accepted `limit` at 200 to prevent tool responses from blowing the context window.
- `Message::toChatCompletionArray()` — strip `reasoning_content` from LLM request payloads; reasoning is internal chain-of-thought and must not be re-injected into subsequent calls (it consumed ~1 000–2 000 tokens per round for nothing).
- `WriterSkill::articleProgress()` — fix article section regex from `/^## /m` to `/^### \d+\./m` to match the actual heading format produced by the model (was always returning 0 sections written).
- `examples/04_writer.php` — reduce continue-step `maxToolRounds` from 4 to 3 (plan read → tail read → append → done); prevents a superfluous verification read that can overflow the context after a large append.

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
