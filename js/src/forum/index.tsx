import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';

app.initializers.add('vendor-flarum-zai', () => {
    extend(IndexPage.prototype, 'oninit', function () {
        console.log('[Flarum Zai] Forum initialized!');
    });
});
