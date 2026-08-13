# ZAI Bot - Flarum AI Assistant

An AI-powered bot extension for [Flarum](https://flarum.org) that automatically replies to forum posts and private messages with intelligent, context-aware responses.

## Features

### Smart Replies
- Responds when mentioned (`@AIGirl`) or randomly at a configurable chance
- Supports both **discussion replies** and **private messages** (via `flarum/messages`)
- Runs via **queue** (`php flarum queue:work`) - non-blocking, scalable

### AI Integration
- **Multi-provider with automatic failover**: configure any number of providers in a graphical editor in the admin panel (each with its own URL, keys and model); if one provider/key fails, the next is tried automatically, and successful endpoints are used round-robin
- Full **tool calling** loop - multi-round tool use for complex tasks
- **Image recognition (vision)**: when a provider is marked as vision-capable, images in the user's post/private message **and in the recent conversation history** are sent to the model (OpenAI-compatible `image_url` content, with captions identifying which message each history image came from), so the bot can see and describe them. Up to 4 images from the current message and 3 from history are included. Images uploaded via `fof/upload` are served through a built-in proxy endpoint (their download route requires the `fof-upload.download` permission, which AI API servers cannot pass), so private/logged-in-only images still work
- **Personality presets**: `friendly`, `tsundere`, `loli`, `cool`, or `custom` (raw system prompt)

### Smart Wake (Proactive Participation)
The bot no longer waits passively to be mentioned — it can proactively join conversations based on context. All trigger detection is local heuristics (no extra model calls), mapped to forum discussions:

- **Explicit / empty mention**: `@AIGirl` always triggers
- **Mention rules**: keyword or `re:` regex rules, scoped to discussions and users — `关键词 @g:123 @u:10001`, blacklist with `!` (`@g:!456`)
- **Probability wake**: existing `Random Reply Chance` setting
- **Relevance wake**: follows up when the new post overlaps the recent topic; denoises ultra-short messages and questions, and picks history length based on message length
- **Expert Q&A**: two-stage detection (question threshold + help keywords) for professional help requests
- **Boredom wake**: detects cold-scene / chat-seeking signals, with a length filter to avoid long narratives
- **Rhythm optimization**: downweights non-bot-directed signals (mentioning others, replying to another post, repeating a context snippet), and gives a small silence bonus when the bot hasn't participated for a long time

Wake types (relevance / expert / boredom) are individually toggled in the admin panel and are off by default.

### Request Orchestration (Message Merging)
- **Hard-wait merge**: with a merge window configured, the reply job is delayed so posts arriving in the window are collected into one request (saves tokens; the merged posts are presented to the AI as one batch)
- **Merge limit**: max messages per request; merged messages may optionally be required to satisfy wake conditions themselves
- **Dynamic recompute**: if newer posts arrive while a reply is being generated, the current result is dropped and the newer post's job recomputes the context
- **Final validation & concurrency**: hidden/recalled posts are filtered out before replying (all invalid → request cancelled), and covering-reply checks prevent duplicate replies from concurrent jobs

### Media Parsing
The bot can understand the *content* of what's posted, not just the text:

- **Link parsing** (`media_link_parse_enabled`): links in posts/messages are fetched server-side and their page title + summary are injected into context. Includes SSRF protection (private IPs / localhost blocked), a domain blacklist, configurable timeout / download-size / per-message link limits, and 24h caching
- **File parsing** (`media_file_parse_enabled`): fof/upload files referenced in a post inject their filename and size; the beginning of text files is read and PDF text is best-effort extracted (uncompressed PDFs only). Results cached for 30 days
- **Image type labeling** (`media_image_classify_enabled`): when sending images to the vision model, emoji / GIF / sticker images are labeled so the model knows it's a sticker rather than a photo
- **Media-only messages**: a post or private message containing only media (no text) gets a text anchor (「用户发布了一条纯媒体消息」) so the AI still responds meaningfully; private-message media always triggers a reply

> Video frame extraction, audio transcription (ASR), QQ-style forwarded-message / JSON-card parsing are **not** included — they require server-side ffmpeg/ASR providers and QQ-specific message types that don't exist on Flarum. GIFs are sent directly to the vision model (OpenAI-compatible APIs accept GIF input).

### Context Injection (Scenario & Identity)
Before replying, the bot can inject a scenario/identity context block and recent discussion events so the model knows *where* the conversation is happening and *what has happened*:

- **Injection timing** (`ctx_inject_timing`): `proactive` (inject before probability/relevance/expert/boredom wakes, not explicit mentions/rules), `all` (inject before every reply), or `off`. Private messages always inject unless set to `off`
- **Event recording** (`ctx_event_record_enabled`): forum events — post hidden (撤回) / restored / deleted / revised, discussion started / renamed / hidden / restored / deleted — are written to a `bot_context_events` log and injected per discussion
- **Environment fields**: platform, channel type, discussion ID/title, current time + weekday, sender user ID, nickname, and user groups are always included in the block
- **Injection format** (`ctx_format`): `concise` (one line per entry) or `detailed` (adds `msg_id`, event type, actor); per-entry truncation via `ctx_entry_max_chars` (default 200) and max events via `ctx_max_events` (default 10)

> QQ-specific context events (mutes, join/leave, poke, essence, join requests) and the `/清除上下文` quick-clear command are not applicable to Flarum and are excluded.

### Time & Weather Awareness
- Auto-injects current date, time, weekday, and Chinese holiday info into system prompt
- Uses `chinese-holidays/holiday-checker` for accurate Chinese statutory holiday detection
- Weather forecast via OpenWeather API (configurable city, 7-day cache)
- Configurable timezone (default: Asia/Shanghai)

### Channel Distinction
- AI knows whether it's replying in a **forum post** or a **private message**
- Adapts tone accordingly: casual/intimate in messages, formal/public in forum posts

### Late Night Care
- When users message the bot between 23:00-06:00, the system prompt includes a caring reminder for the AI to suggest getting rest

### Affinity System
- Per-user multi-dimensional favorability tracking (chat score, forum score, total score)
- Scores increase with each interaction
- AI adjusts tone based on affinity level (from "distant" to "close")
- Admin page to view all user affinities

### Tool System
| Tool | Description | Requires |
|------|-------------|----------|
| `get_user_info` | Query full profile: avatar, cover, bio, birthday, verification, groups, post count | - |
| `view_user_files` | List user-uploaded files (text & images) | `fof/upload` |
| `search_forum` | Search discussions and posts | - |
| `get_stickers` | Browse/search stickers by category | `ramon/stickers` |
| `send_sticker` | Post a sticker in the discussion | `ramon/stickers` |
| `get_post_likes` | Query likes, like, or unlike a post | `flarum/likes` |
| `web_search` | Search the web / read a URL (via Jina) | `jina_optimization_mode` enabled |
| `update_user_portrait` | Record observations and adjust the user's affinity | - |

### Extension Integrations
All integrations are **optional** and loaded via `class_exists()` - no hard dependencies.

- **ramon/verified** - user verification status & tier
- **ramon/stickers** - sticker browsing & sending
- **flarum/likes** - post likes query & toggling
- **flarum/messages** - private message replies
- **fof/user-bio** - user bio in context
- **fof/upload** - user file listing
- **datlechin/flarum-birthdays** - birthday in context
- **forumaker/profile-cover** - cover image in profile

### Realtime (Warble)
Dispatches `Flarum\Post\Event\Posted` after saving bot replies, so `flarum/realtime` (Warble) broadcasts bot responses in real time.

## Installation

```bash
composer require zephyrisle/flarum-zai-bot
```

Then configure in the admin panel under **ZAI Bot** settings.

## Configuration

### Providers
Configure providers in the **graphical editor** at the top of the ZAI Bot admin settings page. Each provider has:

- `name` (optional): shown in admin test results
- `api_url` (required): OpenAI-compatible endpoint
- `api_keys` (required): comma-separated keys with automatic failover
- `model` (required): model to use for this provider
- `enabled`: toggle to temporarily disable a provider
- `vision`: toggle if the model supports image input (vision). When enabled, images posted by users in forum posts or private messages are sent to the model for recognition; providers without vision fall back to text-only automatically

Providers are tried in order; when one fails the next is used automatically. Keys within a provider also fail over, and all endpoints are used round-robin. You can reorder providers with the arrow buttons. Settings are stored as JSON in the `flarum-zai-bot.providers` setting.

Legacy `api_url` / `api_keys` / `model` settings are removed from the admin UI. If they were configured before upgrading, they are imported into the editor on first visit — just review and save. For backward compatibility, provider entries without a `model` (e.g. older JSON configs) fall back to the legacy `model` setting if it still exists in the database, otherwise `gpt-4o-mini`.

### Embedding (Jina)

Embedding is configured **independently** from the LLM providers above — it has its own API URL, API key and model:

- `embedding_api_url` (default `https://api.jina.ai/v1`) — any OpenAI-compatible embeddings endpoint works
- `embedding_api_key` — separate from LLM provider keys
- `embedding_model` (default `jina-embeddings-v3`) — Jina-adapted: requests send `task=text-matching` and `dimensions=1024` for retrieval-optimized vectors

The pgvector `bot_memories.embedding` column is `vector(1024)` and must match the model's output dimension (Jina `jina-embeddings-v3` outputs 1024 dims by default).

### Memory System (Atoms + Hybrid Retrieval)

Memories are stored as **independent atoms** in `bot_memories`, each with its own importance, TTL, reinforcement counter, last-access timestamp, archived flag, and original source text:

- **Memory atoms**: every row has `importance` (0-10, boosted when recalled), `ttl_days`/`expires_at` (expired atoms stop being recalled), `reinforce_count`, `last_accessed_at` (drives time decay), `archived_at` (archived atoms are hidden but recoverable), and `source_text`/`source_meta` (keeps the original message for verification)
- **Hybrid retrieval** (`searchMemories`): semantic path (pgvector cosine similarity top-K) and keyword path (BM25 over query tokens via ILIKE candidates) run in parallel; the two ranked lists are normalized and fused with a configurable weight (`memory_hybrid_vector_weight`, default 60% vector / 40% keyword)
- **Dynamic context**: the fused score is adjusted by importance boost and time decay (`memory_decay_days`, default 30); expired/archived memories are excluded from recall
- **Reinforcement**: every successful recall bumps `importance` +1 (capped at 10) and refreshes `last_accessed_at`
- **Archive & restore**: low-value memories can be archived (hidden from recall) and restored later instead of being hard-deleted; `archiveMemory` / `restoreMemory` / `deleteMemory` / `getMemory` are available programmatically
- **Agent-native tools**: the model can actively recall (`recall_long_term_memory`) and write (`memorize_long_term_memory`, with optional importance/TTL and source retention) memories through the tool system — both registered only when the memory system is available

> The memory graph canvas, per-session/user/global scoping, admin management UI, and background index rebuild are not included in this round.

### Optional Settings
| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Mention wake rules | `wake_mention_rules_enabled` | `false` | Enable keyword/regex wake rules |
| Mention wake rules text | `wake_mention_rules` | - | One rule per line; `re:` regex; `@g:`/`@u:` scopes, `!` = blacklist |
| Relevance wake | `wake_relevance_enabled` | `false` | Proactive follow-up on related topics |
| Expert Q&A | `wake_expert_enabled` | `false` | Detect professional questions/help requests |
| Boredom wake | `wake_boredom_enabled` | `false` | Detect cold-scene / chat-seeking signals |
| Merge window (s) | `wake_merge_seconds` | `0` | >0 = collect posts in the window before one request |
| Merge limit | `wake_merge_max` | `5` | Max messages merged into one request |
| Merged messages must wake | `wake_merge_require_wake` | `false` | Merged posts must also satisfy wake conditions |
| Link parsing | `media_link_parse_enabled` | `false` | Fetch link titles/summaries into context |
| Link domain blacklist | `media_link_blacklist` | - | One domain per line to never fetch |
| Link timeout (s) | `media_link_timeout` | `8` | Per-link request timeout |
| Link size limit (B) | `media_link_max_bytes` | `524288` | Max page bytes read per link |
| Max links per message | `media_link_max_links` | `2` | Links parsed per message (1-5) |
| File parsing | `media_file_parse_enabled` | `false` | Inject fof/upload file names/sizes/previews |
| Image type labeling | `media_image_classify_enabled` | `true` | Label emoji/GIF/sticker images for the model |
| Context injection timing | `ctx_inject_timing` | `proactive` | `proactive` / `all` / `off` — when to inject scenario/identity + events |
| Event recording | `ctx_event_record_enabled` | `true` | Record post/discussion events into the context log |
| Injection format | `ctx_format` | `concise` | `concise` or `detailed` (adds msg_id/actor/type) |
| Max chars per entry | `ctx_entry_max_chars` | `200` | Truncate each injected context entry |
| Max events injected | `ctx_max_events` | `10` | Recent events included per reply (1-50) |
| Hybrid vector weight | `memory_hybrid_vector_weight` | `60` | Vector share of hybrid retrieval (%) |
| Memory decay period | `memory_decay_days` | `30` | Days per decay step for memory ranking |
| Bot Username | `username` | `AIGirl` | Bot's Flarum username |
| Random Reply Chance | `random_reply_chance` | `0` | Auto-reply without mention (%) |
| Reply Decision Window | `reply_cooldown` | `30` | Within this window after the bot's last reply, the AI reviews the new context and decides on its own whether to reply again (`0` = always reply). Note: within this window each trigger consumes an API call even when the bot stays silent |
| Personality | `personality` | `friendly` | `friendly`, `tsundere`, `loli`, `cool`, or `custom` |
| System Prompt | `system_prompt` | - | Raw prompt when personality is `custom` |
| Bot Display Name | `bot_display_name` | `Yuki` | Name shown to users |
| Message Replies | `message_reply_enabled` | `false` | Enable in private messages |
| Timezone | `timezone` | `Asia/Shanghai` | Timezone for date/time in prompts |
| OpenWeather Key | `openweather_key` | - | API key for weather data |
| OpenWeather City | `openweather_city` | `Beijing` | City name for weather forecast |

### Queue Worker
The extension relies on Flarum's queue for async reply generation:

```bash
php flarum queue:work
```

You must keep this running (e.g., via supervisor/systemd) for bot replies to work.

## Tool System Architecture

Tools implement `Zephyrisle\FlarumZaiBot\Service\Tool\ToolInterface`:

```
src/Service/Tool/
├── ToolInterface.php      # Contract (getName, getDescription, getParameters, execute)
├── UserInfoTool.php       # Full profile queries
├── ViewFileTool.php       # User uploaded files via fof/upload
├── SearchTool.php         # Forum search
├── StickerTool.php        # Browse/search stickers
├── SendStickerTool.php    # Send sticker (returns code for TextFormatter)
└── LikeTool.php           # Query likes, like/unlike
```

The AI service (`AIService::generateReply()`) handles:
1. Daily info construction (time, date, holidays, weather, channel context, affinity)
2. System prompt with personality & context
3. Tool definition injection for OpenAI function calling
4. Multi-round tool call loop (recursive `handleToolCalls`)
5. Final content generation

## Development

```bash
cd js && npm install && npm run build
```

## Testing

Unit tests cover `AIService`, `MemoryService`, `ProviderService`, both reply jobs, the listeners and the `BotAffinity` model. They run against an in-memory SQLite database, so no database server is required.

> `flarum/messages` is installed as a dev-only dependency so the private-message job tests can exercise the real `DialogMessage` / `Dialog` / `Created` classes. It is **not** a runtime requirement of the extension.

```bash
composer install
test
```

Or directly:

```bash
vendor/bin/phpunit
```

> Note: while Flarum 2.x is still in RC, `flarum/core` is required as `^2.0@dev` so the test suite (and standalone installs) can resolve the 2.x branch. Once Flarum 2.0 stable is released, this constraint keeps working unchanged.

## License

MIT
