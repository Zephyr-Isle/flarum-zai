import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

export default class AffinitiesPage extends ExtensionPage {
    affinities: any[] | null = null;
    loading = true;
    error: string | null = null;
    testResults: any = {};
    testing: string | null = null;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    testApi(type: string) {
        this.testing = type;
        this.testResults = {};
        m.redraw();

        app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/zai-bot/test-api',
            body: { type },
        })
            .then((data: any) => {
                this.testResults = data;
                this.testing = null;
                m.redraw();
            })
            .catch((e: any) => {
                this.testResults = { error: e.statusText || e.message || 'Request failed' };
                this.testing = null;
                m.redraw();
            });
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

    renderTestResult(result: any): any {
        if (!result) return null;
        if (result.success) {
            const detail = result.reply ? `Reply: ${result.reply}` : `Dimensions: ${result.dimensions}`;
            return m('span', { style: 'color:#2e7d32;font-size:12px' }, `OK - ${detail}`);
        }
        return m('span', { style: 'color:#c62828;font-size:12px' }, `Failed: ${result.error}`);
    }

    content() {
        const testSection = m('div', { style: 'margin-bottom:24px;padding:16px;background:#f6f9fc;border-radius:8px' }, [
            m('h3', { style: 'margin:0 0 8px' }, 'API Test'),
            m('div', { style: 'display:flex;gap:8px;align-items:center' }, [
                m('button', {
                    className: 'Button' + (this.testing === 'all' ? ' Button--loading' : ''),
                    onclick: () => this.testApi('all'),
                    disabled: !!this.testing,
                }, 'Test All'),
                m('button', {
                    className: 'Button' + (this.testing === 'llm' ? ' Button--loading' : ''),
                    onclick: () => this.testApi('llm'),
                    disabled: !!this.testing,
                }, 'Test LLM'),
                m('button', {
                    className: 'Button' + (this.testing === 'embedding' ? ' Button--loading' : ''),
                    onclick: () => this.testApi('embedding'),
                    disabled: !!this.testing,
                }, 'Test Embedding'),
                this.testing ? m('span', ' Testing...') : null,
            ]),
            this.testResults.llm ? m('div', { style: 'margin-top:8px' }, [
                m('strong', 'LLM: '), this.renderTestResult(this.testResults.llm),
            ]) : null,
            this.testResults.embedding ? m('div', { style: 'margin-top:4px' }, [
                m('strong', 'Embedding: '), this.renderTestResult(this.testResults.embedding),
            ]) : null,
            this.testResults.error ? m('div', { style: 'margin-top:8px;color:#c62828' }, this.testResults.error) : null,
        ]);

        return [
            super.content(),
            testSection,
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
