import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';

export default class AffinitiesPage extends ExtensionPage {
    affinities: any[] = [];
    total = 0;
    page = 1;
    limit = 20;
    loading = true;
    error: string | null = null;
    testResults: any = {};
    testing: string | null = null;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    testApi(type: string) {
        if (this.testing) return;
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
                this.testResults = { requestError: e.statusText || e.message || 'Request failed' };
                this.testing = null;
                m.redraw();
            });
    }

    load(page = 1) {
        this.loading = true;
        this.error = null;
        m.redraw();

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/zai-bot/affinities',
            params: { page, limit: this.limit },
        })
            .then((data: any) => {
                this.affinities = data.items || [];
                this.total = data.total || 0;
                this.page = data.page || 1;
                this.loading = false;
                m.redraw();
            })
            .catch((e: any) => {
                this.loading = false;
                this.error = e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    totalPages(): number {
        return Math.max(1, Math.ceil(this.total / this.limit));
    }

    renderPagination(): any {
        const totalPages = this.totalPages();
        const canPrev = this.page > 1;
        const canNext = this.page < totalPages;

        return m('div', { className: 'affinity-pagination' }, [
            m('button', {
                className: 'Button Button--secondary' + (canPrev ? '' : ' disabled'),
                disabled: !canPrev || this.loading,
                title: app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.page_prev'),
                'aria-label': app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.page_prev'),
                onclick: () => this.load(this.page - 1),
            }, '‹'),
            m('span', { className: 'affinity-page-info' }, this.page + ' / ' + totalPages),
            m('button', {
                className: 'Button Button--secondary' + (canNext ? '' : ' disabled'),
                disabled: !canNext || this.loading,
                title: app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.page_next'),
                'aria-label': app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.page_next'),
                onclick: () => this.load(this.page + 1),
            }, '›'),
        ]);
    }

    renderTestResult(key: string, result: any): any {
        if (!result) return null;

        // 无任何已配置端点
        if (!result.success && (!result.items || result.items.length === 0)) {
            return m('span', { className: 'ZaiBot-test-result ZaiBot-test-result--fail' },
                app.translator.trans('zephyrisle-flarum-zai-bot.admin.api_test.failed', { error: result.error || 'Unknown error' }));
        }

        const t = (k: string, p?: any) => app.translator.trans('zephyrisle-flarum-zai-bot.admin.api_test.' + k, p);

        return m('div', { className: 'ZaiBot-test-providers' }, (result.items || []).map((item: any) => {
            if (item.success) {
                const detail = key === 'llm'
                    ? t('success_llm_provider', { name: item.name, model: item.model, reply: item.reply })
                    : t('success_embedding_provider', { name: item.name, model: item.model, dimensions: item.dimensions });
                return m('div', { className: 'ZaiBot-test-result ZaiBot-test-result--ok' }, detail);
            }

            return m('div', { className: 'ZaiBot-test-result ZaiBot-test-result--fail' },
                t('failed_provider', { name: item.name, error: item.error || 'Unknown error' }));
        }));
    }

    content() {
        const t = (key: string, params?: any) => app.translator.trans('zephyrisle-flarum-zai-bot.admin.api_test.' + key, params);

        const testButton = (type: string, label: string) => m('button', {
            className: 'Button' + (this.testing === type ? ' Button--loading' : ''),
            onclick: () => this.testApi(type),
            disabled: !!this.testing,
        }, label);

        const testSection = m('div', { className: 'ZaiBot-test' }, [
            m('h3', { className: 'ZaiBot-test-title' }, t('title')),
            m('p', { className: 'ZaiBot-test-desc' }, t('description')),
            m('div', { className: 'ZaiBot-test-actions' }, [
                testButton('all', t('test_all')),
                testButton('llm', t('test_llm')),
                testButton('embedding', t('test_embedding')),
                this.testing ? m('span', { className: 'ZaiBot-test-spinner' }, t('testing')) : null,
            ]),
            this.testResults.llm ? m('div', { className: 'ZaiBot-test-row' }, [
                m('strong', 'LLM: '), this.renderTestResult('llm', this.testResults.llm),
            ]) : null,
            this.testResults.embedding ? m('div', { className: 'ZaiBot-test-row' }, [
                m('strong', 'Embedding: '), this.renderTestResult('embedding', this.testResults.embedding),
            ]) : null,
            this.testResults.requestError ? m('div', { className: 'ZaiBot-test-row ZaiBot-test-result--fail' }, this.testResults.requestError) : null,
        ]);

        return [
            super.content(),
            testSection,
            m('div', { className: 'ZaiBot-affinities' }, [
                m('div', { className: 'affinity-header' }, [
                    m('h2', app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.title')),
                    m('div', { className: 'affinity-header-actions' }, [
                        !this.loading && this.total > 0
                            ? m('span', { className: 'affinity-total' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.total_records', { total: this.total }))
                            : null,
                        m('button', {
                            className: 'Button Button--refresh' + (this.loading ? ' Button--loading' : ''),
                            disabled: this.loading,
                            onclick: () => this.load(1),
                        }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.refresh')),
                    ]),
                ]),
                m('p', { className: 'affinity-summary' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.description')),
                this.loading && this.affinities.length === 0
                    ? m('div', { className: 'affinity-loading' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.loading'))
                    : null,
                this.error
                    ? m('div', { className: 'Alert Alert--error' }, app.translator.trans('zephyrisle-flarum-zai-bot.admin.affinities.error', { error: this.error }))
                    : this.renderTable(),
            ]),
        ];
    }

    renderTable() {
        if (this.loading && this.affinities.length === 0) {
            return null;
        }

        if (this.affinities.length === 0) {
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
            this.renderPagination(),
        ]);
    }

    renderRow(aff: any) {
        const scoreClass = aff.total_score >= 40 ? 'high' : aff.total_score >= 0 ? 'medium' : 'low';
        const scoreLabel = aff.total_score > 0 ? '+' + aff.total_score : String(aff.total_score);
        return m('tr', [
            m('td', { className: 'affinity-user' }, [
                aff.display_name,
                ' ',
                m('small', '@' + aff.username),
            ]),
            m('td', { className: 'affinity-score ' + scoreClass }, scoreLabel),
            m('td', String(aff.interaction_count)),
            m('td', aff.last_interaction_at || '-'),
        ]);
    }
}
