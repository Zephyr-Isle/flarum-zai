<?php

namespace Zephyrisle\FlarumZaiBot\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

class BotAccountManager
{
    protected array $accounts = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
        $this->loadAccounts();
    }

    protected function loadAccounts(): void
    {
        $raw = $this->settings->get('flarum-zai-bot.accounts', '');
        $entries = $raw ? json_decode($raw, true) : [];

        if (empty($entries)) {
            $username = $this->settings->get('flarum-zai-bot.username', 'AIGirl');
            $entries[] = [
                'username' => $username,
                'display_name' => $this->settings->get('flarum-zai-bot.bot_display_name', 'Yuki'),
                'personality' => $this->settings->get('flarum-zai-bot.personality', 'friendly'),
                'weight' => 100,
                'schedule' => null,
            ];
        }

        foreach ($entries as $entry) {
            $schedule = null;
            if (!empty($entry['schedule'])) {
                $schedule = $entry['schedule'];
            }

            $this->accounts[] = [
                'username' => $entry['username'],
                'display_name' => $entry['display_name'] ?? $entry['username'],
                'personality' => $entry['personality'] ?? 'friendly',
                'weight' => (int) ($entry['weight'] ?? 100),
                'schedule' => $schedule,
                'custom_prompt' => $entry['custom_prompt'] ?? null,
            ];
        }
    }

    public function getActiveAccount(): ?array
    {
        $available = array_filter($this->accounts, fn ($a) => $this->isActive($a));

        if (empty($available)) return null;

        $weighted = [];
        foreach ($available as $a) {
            $weighted = array_merge($weighted, array_fill(0, $a['weight'], $a));
        }

        return $weighted[array_rand($weighted)];
    }

    public function isActive(array $account): bool
    {
        if (!$account['schedule']) return true;

        $hour = (int) Carbon::now()->format('G');
        $schedule = $account['schedule'];

        $active = $schedule['active_hours'] ?? null;
        if ($active !== null) {
            $start = (int) ($active['start'] ?? 0);
            $end = (int) ($active['end'] ?? 24);
            if ($start <= $end) {
                if ($hour < $start || $hour >= $end) return false;
            } else {
                if ($hour < $start && $hour >= $end) return false;
            }
        }

        $weekdays = $schedule['weekdays'] ?? null;
        if ($weekdays !== null) {
            $dayOfWeek = (int) Carbon::now()->format('N');
            if (!in_array($dayOfWeek, $weekdays)) return false;
        }

        $activeChance = $schedule['active_chance'] ?? 100;
        if ($activeChance < 100 && random_int(1, 100) > $activeChance) return false;

        return true;
    }

    public function getPersonalityPrompt(array $account): string
    {
        if ($account['custom_prompt']) return $account['custom_prompt'];

        $personalities = [
            'friendly' => '你是一个友好热情的论坛成员。你乐于助人、耐心细致，回复自然温暖。',
            'tsundere' => '你是一个傲娇的论坛成员。你说话带刺但实际很关心别人，常用"哼"、"才不是"、"笨蛋"等词。',
            'loli' => '你是一个可爱的论坛成员。说话活泼可爱，带语气词"啦""呀""呢"，自称"人家"。',
            'cool' => '你是一个高冷寡言的论坛成员。说话简洁直接，只说重点。',
            'student' => '你是一个学生。上课时（8:00-17:00）回复简短，课间和放学后更活跃热情。说话带有学生特有的语气和网络用语。',
            'elder' => '你是一个稳重的年长论坛成员。说话客气礼貌，常用敬语，偶尔分享人生经验。',
            'tech' => '你是一个技术爱好者。说话带有极客风格，喜欢使用专业术语，热衷于讨论技术话题。',
        ];

        $base = $personalities[$account['personality']] ?? $personalities['friendly'];

        if ($account['schedule']) {
            $hour = (int) Carbon::now()->format('G');
            $active = $account['schedule']['active_hours'] ?? null;
            if ($active) {
                $start = (int) ($active['start'] ?? 0);
                $end = (int) ($active['end'] ?? 24);
                $isActive = $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
                if (!$isActive) {
                    $base .= "\n\n你现在处于休息时间，回复应尽可能简短（1-2句话）。";
                } else {
                    $base .= "\n\n你现在处于活跃时间，可以充分参与讨论。";
                }
            }
        }

        return $base;
    }

    public function getOrCreateBotUser(string $username): User
    {
        $user = User::where('username', $username)->first();
        if (!$user) {
            $user = new User();
            $user->username = $username;
            $user->email = $username . '@bot.local';
            $user->password = \Illuminate\Support\Str::random(40);
            $user->is_email_confirmed = true;
            $user->save();
            $user->groups()->sync([1]);
        }
        return $user;
    }
}
