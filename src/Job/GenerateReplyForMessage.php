<?php

namespace Zephyrisle\FlarumZaiBot\Job;

use Carbon\Carbon;
use Flarum\Messages\DialogMessage;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Zephyrisle\FlarumZaiBot\Model\BotAffinity;
use Zephyrisle\FlarumZaiBot\Service\AIService;
use Zephyrisle\FlarumZaiBot\Service\MemoryService;
use Zephyrisle\FlarumZaiBot\Service\PortraitService;
use Zephyrisle\FlarumZaiBot\Service\Tool\LikeTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\SendStickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\StickerTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UpdatePortraitTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\WebSearchTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\UserInfoTool;
use Zephyrisle\FlarumZaiBot\Service\Tool\ViewFileTool;

class GenerateReplyForMessage extends AbstractJob
{
    public int $messageId;

    public function __construct(int $messageId)
    {
        $this->messageId = $messageId;
    }

    public function handle(AIService $ai, SettingsRepositoryInterface $settings): void
    {
        $message = DialogMessage::find($this->messageId);

        if (!$message || !$message->dialog) {
            return;
        }

        $dialog = $message->dialog;

        if (!(bool) $settings->get('flarum-zai-bot.message_reply_enabled', false)) {
            return;
        }

        $botUsername = $settings->get('flarum-zai-bot.username', 'AIGirl');

        $botUser = $this->getBotUser($botUsername);

        if ($message->user_id === $botUser->id) {
            return;
        }

        $dialogUserIds = $dialog->users()->pluck('user_id')->toArray();

        if (!in_array($botUser->id, $dialogUserIds, true)) {
            return;
        }

        $author = $message->user;
        $isVerified = false;
        if ($author && class_exists(\Ramon\Verified\TierResolver::class)) {
            $resolver = resolve(\Ramon\Verified\TierResolver::class);
            $isVerified = $resolver->isVerified($author);
        }

        $history = [];
        $recentMessages = DialogMessage::where('dialog_id', $dialog->id)
            ->where('id', '<', $message->id)
            ->orderBy('id', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        foreach ($recentMessages as $prevMsg) {
            $prevAuthor = $prevMsg->user;
            $history[] = [
                'author' => $prevAuthor ? $prevAuthor->display_name : '未知',
                'content' => $prevMsg->content,
            ];
        }

        $affinity = null;
        $portraitSummary = null;
        $memories = [];
        $userId = $author ? $author->id : null;

        if ($author) {
            $affinity = BotAffinity::getOrCreate($author->id);

            try {
                $portraitService = resolve(PortraitService::class);
                $portraitSummary = $portraitService->getPortraitSummary($author->id);
            } catch (\Exception $e) {
            }

            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $keys = $this->getApiKeys($settings);
                    $embedding = $memoryService->generateEmbedding($message->content, $keys);
                    if ($embedding) {
                        $memories = $memoryService->searchMemories($author->id, $embedding, 5);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $context = [
            'channel' => 'message',
            'user_id' => $userId,
            'username' => $author ? $author->username : 'unknown',
            'display_name' => $author ? $author->display_name : '未知',
            'is_verified' => $isVerified,
            'affinity_score' => $affinity?->total_score ?? null,
            'portrait_summary' => $portraitSummary,
            'memories' => $memories,
            'conversation_history' => $history,
        ];

        if ($author) {
            $context['joined_at'] = $author->joined_at ? $author->joined_at->format('Y-m-d H:i:s') : null;
            $context['post_count'] = $author->posts()->count();
            $context['group_names'] = $author->groups->pluck('name_singular')->implode(', ') ?: null;

            if (class_exists(\FoF\UserBio\Event\BioChanged::class) && $author->bio) {
                $context['bio'] = strip_tags($author->bio);
            }

            if (class_exists(\Datlechin\Birthdays\AddBirthdayValidation::class) && $author->birthday) {
                $context['birthday'] = $author->birthday;
            }

            if (class_exists(\Ramon\Verified\Models\UserVerification::class)) {
                $verification = \Ramon\Verified\Models\UserVerification::where('user_id', $author->id)->first();
                if ($verification) {
                    $context['verified_tier'] = $verification->verified_tier;
                    $context['verified_at'] = $verification->verified_at ? $verification->verified_at->format('Y-m-d H:i:s') : null;
                }
            }
        }

        $portraitTool = new UpdatePortraitTool(resolve(PortraitService::class), $userId);
        $tools = [new UserInfoTool(), new SearchTool(), new ViewFileTool(), new StickerTool(), new SendStickerTool(), new LikeTool($botUser->id), $portraitTool, resolve(WebSearchTool::class)];

        $reply = $ai->generateReply($message->content, $context, $tools);

        if ($reply && $userId) {
            $reply = $ai->parseSecretEval($reply, $userId);
        }

        if (!$reply) {
            error_log('[flarum-zai-bot] GenerateReplyForMessage: generateReply returned null. message_id=' . $message->id);
            return;
        }

        if ($author && $userId) {
            try {
                $memoryService = resolve(MemoryService::class);
                if ($memoryService->isAvailable()) {
                    $embeddingKeys = $this->getEmbeddingApiKeys($settings);
                    $embedding = $memoryService->generateEmbedding($message->content . "\n" . strip_tags($reply), $embeddingKeys);
                    if ($embedding) {
                        $memoryService->storeMemory($userId, "私信对话：{$message->content}\nAI回复：" . strip_tags($reply), $embedding);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        $botMessage = new DialogMessage();
        $botMessage->dialog_id = $dialog->id;
        $botMessage->user_id = $botUser->id;
        $botMessage->content = $reply;
        $botMessage->save();

        $botMessage->refresh();

        $dialog->setLastMessage($botMessage);
        $dialog->save();
    }

    protected function getApiKeys(SettingsRepositoryInterface $settings): array
    {
        $raw = $settings->get('flarum-zai-bot.api_keys', '');
        return array_filter(array_map('trim', explode(',', $raw))) ?: [];
    }

    protected function getEmbeddingApiKeys(SettingsRepositoryInterface $settings): array
    {
        $raw = $settings->get('flarum-zai-bot.embedding_api_keys', '');
        $keys = array_filter(array_map('trim', explode(',', $raw)));
        if (!empty($keys)) {
            return $keys;
        }
        return $this->getApiKeys($settings);
    }

    protected function getBotUser(string $botUsername): User
    {
        $botUser = User::where('username', $botUsername)->first();

        if (!$botUser) {
            $botUser = new User();
            $botUser->username = $botUsername;
            $botUser->email = $botUsername . '@bot.local';
            $botUser->password = \Illuminate\Support\Str::random(40);
            $botUser->is_email_confirmed = true;
            $botUser->save();
            $botUser->groups()->sync([1]);
        }

        $botUser->last_seen_at = Carbon::now();
        $botUser->save();

        return $botUser;
    }
}
