import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

export default class AffinitiesPage extends ExtensionPage {
    affinities: any[] | null = null;
    loading = true;
    error: string | null = null;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    load() {
        this.loading = true;
        this.error = null;
        m.redraw();

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/zai-bot/affinities',
        })
            .then((data: any) => {
                this.affinities = data;
                this.loading = false;
                m.redraw();
            })
            .catch((e: any) => {
                this.loading = false;
                this.error = e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    content() {
        return [
            super.content(),
            m('div', { className: 'ZaiBot-affinities' }, [
                m('div', { className: 'affinity-header' }, [
                    m('h2', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.title')),
                    this.loading
                        ? m('span', { className: 'affinity-loading' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.loading'))
                        : m('button', { className: 'Button Button--refresh', onclick: () => this.load() }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.refresh')),
                ]),
                m('p', { className: 'affinity-summary' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.description')),
                this.error
                    ? m('div', { className: 'Alert Alert--error' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.error', { error: this.error }))
                    : this.renderTable(),
            ]),
        ];
    }

    renderTable() {
        if (!this.affinities || this.affinities.length === 0) {
            return m('div', { className: 'affinity-empty' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.no_affinities'));
        }

        return m('div', { className: 'affinity-table-wrap' }, [
            m('table', [
                m('thead', [
                    m('tr', [
                        m('th', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.table.user')),
                        m('th', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.table.total_score')),
                        m('th', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.table.interactions')),
                        m('th', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.table.last_interaction')),
                    ]),
                ]),
                m('tbody', this.affinities.map((aff: any) => this.renderRow(aff))),
            ]),
        ]);
    }

    renderRow(aff: any) {
        const scoreClass = aff.total_score >= 40 ? 'high' : aff.total_score >= 0 ? 'medium' : 'low';
        return m('tr', [
            m('td', { className: 'affinity-user' }, [
                aff.display_name,
                ' ',
                m('small', '@' + aff.username),
            ]),
            m('td', { className: 'affinity-score ' + scoreClass }, String(aff.total_score)),
            m('td', String(aff.interaction_count)),
            m('td', aff.last_interaction_at || '-'),
        ]);
    }
}
