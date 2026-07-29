# ZAI Bot - Flarum AI Assistant

An AI-powered bot extension for [Flarum](https://flarum.org) that automatically replies to forum posts and private messages with intelligent, context-aware responses.

## Features

### Smart Replies
- Responds when mentioned (`@AIGirl`) or randomly at a configurable chance
- Supports both **discussion replies** and **private messages** (via `flarum/messages`)
- Runs via **queue** (`php flarum queue:work`) - non-blocking, scalable

### AI Integration
- Self-hosted or OpenAI-compatible API (configure URL, key, model)
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

### Required Settings
| Setting | Key | Description |
|---------|-----|-------------|
| API URL | `api_url` | OpenAI-compatible endpoint (default: `https://api.openai.com/v1`) |
| API Key | `api_key` | Your API key |
| Model | `model` | Model name (default: `gpt-3.5-turbo`) |
| Bot Username | `username` | Bot's Flarum username (default: `AIGirl`) |

### Optional Settings
| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| Random Reply Chance | `random_reply_chance` | `0` | Auto-reply without mention (%) |
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

## License

MIT
