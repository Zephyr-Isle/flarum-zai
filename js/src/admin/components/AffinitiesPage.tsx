import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import ProvidersSettings from './ProvidersSettings';

const EMOTION_KEYS = ['joy', 'trust', 'fear', 'surprise', 'sadness', 'disgust', 'anger', 'anticipation', 'pride', 'guilt', 'shame', 'envy'];

export default class AffinitiesPage extends ExtensionPage {
    affinities: any[] = [];
    total = 0;
    page = 1;
    limit = 20;
    loading = true;
    error: string | null = null;
    testResults: any = {};
    testing: string | null = null;

    q = '';

    editingId: number | null = null;
    editFavor = 0;
    editTrust = 0;
    editIntimacy = 0;
    editEmotions: Record<string, string> = {};
    editAttitude = '';
    editRelationship = '';
    editBlacklisted = false;
    saving = false;

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
            params: { page, limit: this.limit, q: this.q || undefined },
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
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
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
                title: this.t('affinities.page_prev'),
                onclick: () => this.load(this.page - 1),
            }, '‹'),
            m('span', { className: 'affinity-page-info' }, this.page + ' / ' + totalPages),
            m('button', {
                className: 'Button Button--secondary' + (canNext ? '' : ' disabled'),
                disabled: !canNext || this.loading,
                title: this.t('affinities.page_next'),
                onclick: () => this.load(this.page + 1),
            }, '›'),
        ]);
    }

    renderTestResult(key: string, result: any): any {
        if (!result) return null;

        // 无任何已配置端点
        if (!result.success && (!result.items || result.items.length === 0)) {
            return m('span', { className: 'ZaiBot-test-result ZaiBot-test-result--fail' },
                this.t('api_test.failed', { error: result.error || 'Unknown error' }));
        }

        return m('div', { className: 'ZaiBot-test-providers' }, (result.items || []).map((item: any) => {
            if (item.success) {
                const detail = key === 'llm'
                    ? this.t('api_test.success_llm_provider', { name: item.name, model: item.model, reply: item.reply })
                    : this.t('api_test.success_embedding_provider', { name: item.name, model: item.model, dimensions: item.dimensions });
                return m('div', { className: 'ZaiBot-test-result ZaiBot-test-result--ok' }, detail);
            }

            return m('div', { className: 'ZaiBot-test-result ZaiBot-test-result--fail' },
                this.t('api_test.failed_provider', { name: item.name, error: item.error || 'Unknown error' }));
        }));
    }

    content() {
        const testButton = (type: string, label: string) => m('button', {
            className: 'Button' + (this.testing === type ? ' Button--loading' : ''),
            onclick: () => this.testApi(type),
            disabled: !!this.testing,
        }, label);

        const testSection = m('div', { className: 'ZaiBot-test' }, [
            m('h3', { className: 'ZaiBot-test-title' }, this.t('api_test.title')),
            m('p', { className: 'ZaiBot-test-desc' }, this.t('api_test.description')),
            m('div', { className: 'ZaiBot-test-actions' }, [
                testButton('all', this.t('api_test.test_all')),
                testButton('llm', this.t('api_test.test_llm')),
                testButton('embedding', this.t('api_test.test_embedding')),
                this.testing ? m('span', { className: 'ZaiBot-test-spinner' }, this.t('api_test.testing')) : null,
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
            m(ProvidersSettings, { stream: this.setting('flarum-zai-bot.providers') }),
            super.content(),
            testSection,
            m('div', { className: 'ZaiBot-affinities' }, [
                m('div', { className: 'affinity-header' }, [
                    m('h2', this.t('affinities.title')),
                    m('div', { className: 'affinity-header-actions' }, [
                        !this.loading && this.total > 0
                            ? m('span', { className: 'affinity-total' }, this.t('affinities.total_records', { total: this.total }))
                            : null,
                        m('button', {
                            className: 'Button Button--refresh' + (this.loading ? ' Button--loading' : ''),
                            disabled: this.loading,
                            onclick: () => this.load(1),
                        }, this.t('affinities.refresh')),
                    ]),
                ]),
                m('p', { className: 'affinity-summary' }, this.t('affinities.description')),
                m('div', { className: 'affinity-filters' }, [
                    m('input', {
                        className: 'FormControl',
                        type: 'text',
                        placeholder: this.t('affinities.filter_placeholder'),
                        value: this.q,
                        oninput: (e: any) => { this.q = e.target.value; },
                    }),
                    m('button', {
                        className: 'Button Button--primary',
                        disabled: this.loading,
                        onclick: () => this.load(1),
                    }, this.t('affinities.filter_apply')),
                ]),
                this.loading && this.affinities.length === 0
                    ? m('div', { className: 'affinity-loading' }, this.t('affinities.loading'))
                    : null,
                this.error
                    ? m('div', { className: 'Alert Alert--error' }, this.t('affinities.error', { error: this.error }))
                    : this.renderTable(),
            ]),
        ];
    }

    renderTable() {
        if (this.loading && this.affinities.length === 0) {
            return null;
        }

        if (this.affinities.length === 0) {
            return m('div', { className: 'affinity-empty' }, this.t('affinities.no_affinities'));
        }

        return m('div', { className: 'affinity-table-wrap' }, [
            m('table', [
                m('thead', [
                    m('tr', [
                        m('th', this.t('affinities.table.user')),
                        m('th', this.t('affinities.table.favor')),
                        m('th', this.t('affinities.table.trust')),
                        m('th', this.t('affinities.table.intimacy')),
                        m('th', this.t('affinities.table.level')),
                        m('th', this.t('affinities.table.interactions')),
                        m('th', this.t('affinities.table.last_interaction')),
                        m('th', this.t('affinities.table.status')),
                        m('th', this.t('affinities.table.actions')),
                    ]),
                ]),
                m('tbody', this.affinities.map((aff: any) => this.renderRow(aff))),
            ]),
            this.renderPagination(),
        ]);
    }

    renderRow(aff: any) {
        const isEditing = this.editingId === aff.user_id;

        if (isEditing) {
            return this.renderEditRow(aff);
        }

        const scoreClass = aff.total_score >= 40 ? 'high' : aff.total_score >= 0 ? 'medium' : 'low';
        const scoreLabel = aff.total_score > 0 ? '+' + aff.total_score : String(aff.total_score);

        return m('tr', { className: aff.blacklisted ? 'affinity-row--blacklisted' : '' }, [
            m('td', { className: 'affinity-user' }, [
                aff.display_name,
                ' ',
                m('small', '@' + aff.username),
            ]),
            m('td', { className: 'affinity-score ' + scoreClass }, scoreLabel),
            m('td', { className: 'affinity-sub' }, String(aff.trust ?? 0)),
            m('td', { className: 'affinity-sub' }, String(aff.intimacy ?? 0)),
            m('td', { className: 'affinity-level' }, this.levelLabel(aff.total_score)),
            m('td', String(aff.interaction_count)),
            m('td', aff.last_interaction_at || '-'),
            m('td', { className: 'affinity-status' }, aff.blacklisted ? this.t('affinities.blacklisted') : this.t('affinities.normal')),
            m('td', { className: 'affinity-actions' }, [
                m('button', {
                    className: 'Button Button--secondary',
                    onclick: () => this.startEdit(aff),
                }, this.t('affinities.edit')),
            ]),
        ]);
    }

    renderEditRow(aff: any) {
        const emotionInputs = EMOTION_KEYS.map((key) => m('div', { className: 'affinity-emotion' }, [
            m('label', {}, this.emotionLabel(key)),
            m('input', {
                className: 'FormControl',
                type: 'number',
                min: -100,
                max: 100,
                value: this.editEmotions[key] ?? '0',
                oninput: (e: any) => { this.editEmotions[key] = e.target.value; },
            }),
        ]));

        return m('tr', { className: 'affinity-row--editing' }, [
            m('td', { colSpan: 9 }, [
                m('div', { className: 'affinity-editor' }, [
                    m('div', { className: 'affinity-editor-main' }, [
                        m('div', { className: 'affinity-editor-field' }, [
                            m('label', {}, this.t('affinities.edit_favor')),
                            m('input', {
                                className: 'FormControl',
                                type: 'number',
                                min: -100,
                                max: 100,
                                value: String(this.editFavor),
                                oninput: (e: any) => { this.editFavor = parseInt(e.target.value, 10) || 0; },
                            }),
                        ]),
                        m('div', { className: 'affinity-editor-field' }, [
                            m('label', {}, this.t('affinities.edit_trust')),
                            m('input', {
                                className: 'FormControl',
                                type: 'number',
                                min: -100,
                                max: 100,
                                value: String(this.editTrust),
                                oninput: (e: any) => { this.editTrust = parseInt(e.target.value, 10) || 0; },
                            }),
                        ]),
                        m('div', { className: 'affinity-editor-field' }, [
                            m('label', {}, this.t('affinities.edit_intimacy')),
                            m('input', {
                                className: 'FormControl',
                                type: 'number',
                                min: -100,
                                max: 100,
                                value: String(this.editIntimacy),
                                oninput: (e: any) => { this.editIntimacy = parseInt(e.target.value, 10) || 0; },
                            }),
                        ]),
                        m('div', { className: 'affinity-editor-field affinity-editor-field--wide' }, [
                            m('label', {}, this.t('affinities.edit_attitude')),
                            m('input', {
                                className: 'FormControl',
                                type: 'text',
                                value: this.editAttitude,
                                oninput: (e: any) => { this.editAttitude = e.target.value; },
                            }),
                        ]),
                        m('div', { className: 'affinity-editor-field affinity-editor-field--wide' }, [
                            m('label', {}, this.t('affinities.edit_relationship')),
                            m('input', {
                                className: 'FormControl',
                                type: 'text',
                                value: this.editRelationship,
                                oninput: (e: any) => { this.editRelationship = e.target.value; },
                            }),
                        ]),
                        m('label', { className: 'affinity-editor-blacklist' }, [
                            m('input', {
                                type: 'checkbox',
                                checked: this.editBlacklisted,
                                onchange: (e: any) => { this.editBlacklisted = e.target.checked; },
                            }),
                            ' ',
                            this.t('affinities.edit_blacklist'),
                        ]),
                    ]),
                    m('div', { className: 'affinity-editor-emotions' }, emotionInputs),
                    m('div', { className: 'affinity-editor-actions' }, [
                        m('button', {
                            className: 'Button Button--primary' + (this.saving ? ' Button--loading' : ''),
                            disabled: this.saving,
                            onclick: () => this.saveEdit(aff),
                        }, this.t('affinities.save')),
                        m('button', {
                            className: 'Button Button--secondary',
                            disabled: this.saving,
                            onclick: () => { this.editingId = null; },
                        }, this.t('affinities.cancel')),
                        m('button', {
                            className: 'Button Button--danger',
                            disabled: this.saving,
                            onclick: () => {
                                if (confirm(this.t('affinities.confirm_reset'))) {
                                    this.reset(aff);
                                }
                            },
                        }, this.t('affinities.reset')),
                    ]),
                ]),
            ]),
        ]);
    }

    startEdit(aff: any) {
        this.editingId = aff.user_id;
        this.editFavor = aff.total_score ?? 0;
        this.editTrust = aff.trust ?? 0;
        this.editIntimacy = aff.intimacy ?? 0;
        this.editEmotions = {};
        for (const key of EMOTION_KEYS) {
            this.editEmotions[key] = String(aff.emotions?.[key] ?? 0);
        }
        this.editAttitude = aff.attitude ?? '';
        this.editRelationship = aff.relationship ?? '';
        this.editBlacklisted = !!aff.blacklisted;
        m.redraw();
    }

    saveEdit(aff: any) {
        this.saving = true;
        m.redraw();

        const emotions: Record<string, number> = {};
        for (const key of EMOTION_KEYS) {
            emotions[key] = parseInt(this.editEmotions[key] ?? '0', 10) || 0;
        }

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/affinities/' + aff.user_id,
            body: {
                favor: this.editFavor,
                trust: this.editTrust,
                intimacy: this.editIntimacy,
                emotions,
                attitude: this.editAttitude,
                relationship: this.editRelationship,
                blacklisted: this.editBlacklisted,
            },
        })
            .then(() => {
                this.saving = false;
                this.editingId = null;
                this.load(this.page);
            })
            .catch((e: any) => {
                this.saving = false;
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    reset(aff: any) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/affinities/' + aff.user_id,
            body: { reset: true },
        })
            .then(() => {
                this.saving = false;
                this.editingId = null;
                this.load(this.page);
            })
            .catch((e: any) => {
                this.saving = false;
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    levelLabel(score: number): string {
        if (score >= 75) return this.t('affinities.level_intimate');
        if (score >= 40) return this.t('affinities.level_friendly');
        if (score >= -10) return this.t('affinities.level_neutral');
        if (score >= -50) return this.t('affinities.level_cold');
        return this.t('affinities.level_hostile');
    }

    emotionLabel(key: string): string {
        const labels: Record<string, string> = {
            joy: '喜悦', trust: '信任', fear: '恐惧', surprise: '惊讶',
            sadness: '悲伤', disgust: '厌恶', anger: '愤怒', anticipation: '期待',
            pride: '得意', guilt: '愧疚', shame: '羞耻', envy: '嫉妒',
        };
        return labels[key] || key;
    }

    t(key: string, params?: any) {
        return app.translator.trans('zephyrisle-flarum-zai-bot.admin.' + key, params);
    }
}
