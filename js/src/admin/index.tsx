import app from 'flarum/admin/app';

app.initializers.add('vendor-flarum-zai', () => {
    app.extensionData
        .for('vendor-flarum-zai')
        .registerSetting({
            setting: 'flarum-zai-bot.api_url',
            label: 'API URL',
            type: 'text',
            default: 'https://api.openai.com/v1',
        })
        .registerSetting({
            setting: 'flarum-zai-bot.api_key',
            label: 'API Key',
            type: 'password',
        })
        .registerSetting({
            setting: 'flarum-zai-bot.model',
            label: 'Model',
            type: 'text',
            default: 'gpt-3.5-turbo',
        })
        .registerSetting({
            setting: 'flarum-zai-bot.username',
            label: 'Bot Username',
            type: 'text',
            default: 'AIGirl',
        })
        .registerSetting({
            setting: 'flarum-zai-bot.random_reply_chance',
            label: 'Random Reply Chance (%)',
            type: 'number',
            default: 0,
            min: 0,
            max: 100,
        })
        .registerSetting({
            setting: 'flarum-zai-bot.message_extension',
            label: 'Message Extension (reply in active discussions)',
            type: 'boolean',
            default: false,
        })
        .registerSetting({
            setting: 'flarum-zai-bot.system_prompt',
            label: 'System Prompt',
            type: 'textarea',
            default: 'You are a friendly community forum assistant. Keep responses concise and helpful.',
        });
});
