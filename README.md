# ZAI Bot - Flarum AI Assistant

An AI-powered bot extension for [Flarum](https://flarum.org) that automatically replies to forum posts and private messages with intelligent, context-aware responses.

## Features

### Smart Replies
- Responds when mentioned (`@AIGirl`) or randomly at a configurable chance
- Supports both **discussion replies** and **private messages** (via `flarum/messages`)
- Runs via **queue** (`php flarum queue:work`) - non-blocking, scalable

### AI Integration
- **Multi-provider with automatic failover**: configure any number of providers (each with its own URL, keys and model) as JSON; if one provider/key fails, the next is tried automatically, and successful endpoints are used round-robin
- Legacy single-URL settings remain fully supported (fallback when no providers are configured)
- Full **tool calling** loop - multi-round tool use for complex tasks
- **Personality presets**: `friendly`, `tsundere`, `loli`, `cool`, or `custom` (raw system prompt)

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

### Providers (recommended)
Configure one or more providers as a JSON array in the `providers` setting. Each entry can have its own URL, keys and model; providers are tried in order, and when one fails the next is used automatically. Keys within a provider also fail over, and all endpoints are used round-robin.

```json
[
  {"name": "DeepSeek", "api_url": "https://api.deepseek.com/v1", "api_keys": "sk-a,sk-b", "model": "deepseek-chat"},
  {"name": "OpenAI",   "api_url": "https://api.openai.com/v1",   "api_keys": "sk-c",         "model": "gpt-4o-mini"}
]
```

- `name` (optional): shown in admin test results
- `api_url` (required): OpenAI-compatible endpoint
- `api_keys` (required): comma-separated keys with automatic failover
- `model` (optional): per-provider model; defaults to the global `model` setting
- `enabled` (optional, default `true`): set `false` to temporarily disable a provider

Embedding requests use the same provider list (with the global `embedding_model`).

### Required Settings (legacy, used only when `providers` is empty)
| Setting | Key | Description |
|---------|-----|-------------|
| API URL | `api_url` | OpenAI-compatible endpoint (default: `https://api.openai.com/v1`) |
| API Key | `api_keys` | Comma-separated API keys (auto-failover & round-robin) |
| Model | `model` | Model name (default: `gpt-3.5-turbo`) |
| Bot Username | `username` | Bot's Flarum username (default: `AIGirl`) |

### Optional Settings
| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
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
