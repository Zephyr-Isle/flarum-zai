import app from 'flarum/admin/app';
import ProviderListEditor from './components/ProviderListEditor';
import AccountListEditor from './components/AccountListEditor';

app.initializers.add('zephyrisle-zai-bot', () => {
    app.extensionData
        .for('zephyrisle-zai-bot')
        .registerSetting(() => m(ProviderListEditor))
        .registerSetting(() => m(AccountListEditor));
});
