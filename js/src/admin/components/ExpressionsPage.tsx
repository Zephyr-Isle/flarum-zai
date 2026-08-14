import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

/**
 * 后台表达学习页：待审核/已启用/已禁用三态切换。
 * 待审核规则不进入回复；审核通过后才生效。
 * 可编辑：名称、情境、模板、句法、召回标签、适用边界；
 * 证据（evidence）与使用统计（use_count）只读。
 */
export default class ExpressionsPage extends Component {
    items: any[] = [];
    total = 0;
    page = 1;
    limit = 20;
    loading = true;
    error: string | null = null;

    counts: any = { pending: 0, active: 0, disabled: 0 };
    status = 'pending';
    q = '';

    editingId: number | null = null;
    editName = '';
    editSituation = '';
    editTemplate = '';
    editSyntax = '';
    editTags = '';
    editScope = '';
    saving = false;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    view() {
        return m('div', { className: 'ZaiBot-memories ZaiBot-expressions' }, [
            m('div', { className: 'memory-header' }, [
                m('h2', this.t('title')),
                m('div', { className: 'memory-header-actions' }, [
                    !this.loading && this.total > 0
                        ? m('span', { className: 'memory-total' }, this.t('total_records', { total: this.total }))
                        : null,
                    m('button', {
                        className: 'Button Button--refresh' + (this.loading ? ' Button--loading' : ''),
                        disabled: this.loading,
                        onclick: () => this.load(1),
                    }, this.t('refresh')),
                ]),
            ]),
            m('p', { className: 'memory-summary' }, this.t('description')),

            m('div', { className: 'memory-filters' }, [
                m('div', { className: 'expression-tabs' }, [
                    this.renderTab('pending', 'tab_pending'),
                    this.renderTab('active', 'tab_active'),
                    this.renderTab('disabled', 'tab_disabled'),
                ]),
                m('input', {
                    className: 'FormControl expression-search',
                    type: 'text',
                    placeholder: this.t('filter_q_placeholder'),
                    value: this.q,
                    oninput: (e: any) => { this.q = e.target.value; },
                }),
                m('button', {
                    className: 'Button Button--primary',
                    disabled: this.loading,
                    onclick: () => this.load(1),
                }, this.t('filter_apply')),
            ]),

            this.error
                ? m('div', { className: 'Alert Alert--error' }, this.t('error', { error: this.error }))
                : this.renderTable(),
        ]);
    }

    renderTab(status: string, labelKey: string) {
        const count = this.counts[status] ?? 0;

        return m('button', {
            className: 'Button expression-tab' + (this.status === status ? ' expression-tab--active' : ''),
            onclick: () => {
                this.status = status;
                this.load(1);
            },
        }, this.t(labelKey) + ' (' + count + ')');
    }

    renderTable() {
        if (this.loading && this.items.length === 0) {
            return m('div', { className: 'memory-loading' }, this.t('loading'));
        }

        if (this.items.length === 0) {
            return m('div', { className: 'memory-empty' }, this.t('no_expressions'));
        }

        return m('div', { className: 'memory-table-wrap' }, [
            m('table', [
                m('thead', [
                    m('tr', [
                        m('th', this.t('table.name')),
                        m('th', this.t('table.situation')),
                        m('th', this.t('table.template')),
                        m('th', this.t('table.tags')),
                        m('th', this.t('table.source')),
                        m('th', this.t('table.usage')),
                        m('th', this.t('table.actions')),
                    ]),
                ]),
                m('tbody', this.items.map((item) => this.renderRow(item))),
            ]),
            this.renderPagination(),
        ]);
    }

    renderRow(item: any) {
        const isEditing = this.editingId === item.id;

        const rows: any[] = [
            m('tr', { className: 'expression-row expression-row--' + item.status }, [
                m('td', { className: 'expression-name' }, isEditing
                    ? m('input', {
                        className: 'FormControl',
                        type: 'text',
                        value: this.editName,
                        oninput: (e: any) => { this.editName = e.target.value; },
                    })
                    : m('strong', item.name)),
                m('td', { className: 'expression-cell' }, isEditing
                    ? m('input', {
                        className: 'FormControl',
                        type: 'text',
                        placeholder: this.t('situation_placeholder'),
                        value: this.editSituation,
                        oninput: (e: any) => { this.editSituation = e.target.value; },
                    })
                    : (item.situation || '-')),
                m('td', { className: 'expression-cell' }, isEditing
                    ? m('textarea', {
                        className: 'FormControl expression-template-input',
                        rows: 2,
                        value: this.editTemplate,
                        oninput: (e: any) => { this.editTemplate = e.target.value; },
                    })
                    : item.template),
                m('td', { className: 'expression-cell' }, isEditing
                    ? m('input', {
                        className: 'FormControl',
                        type: 'text',
                        placeholder: this.t('tags_placeholder'),
                        value: this.editTags,
                        oninput: (e: any) => { this.editTags = e.target.value; },
                    })
                    : (this.formatList(item.recall_tags) || '-')),
                m('td', { className: 'expression-cell' }, this.sourceLabel(item.source_type)),
                m('td', { className: 'expression-usage' }, String(item.use_count)),
                m('td', { className: 'memory-actions' }, isEditing ? this.renderEditActions() : this.renderRowActions(item)),
            ]),
            isEditing
                ? m('tr', { className: 'expression-editor-row' }, [
                    m('td', { colSpan: 7 }, [
                        m('div', { className: 'expression-editor' }, [
                            m('label', { className: 'expression-editor-field' }, [
                                m('span', { className: 'expression-editor-label' }, this.t('table.syntax')),
                                m('input', {
                                    className: 'FormControl',
                                    type: 'text',
                                    value: this.editSyntax,
                                    oninput: (e: any) => { this.editSyntax = e.target.value; },
                                }),
                            ]),
                            m('label', { className: 'expression-editor-field' }, [
                                m('span', { className: 'expression-editor-label' }, this.t('table.scope')),
                                m('input', {
                                    className: 'FormControl',
                                    type: 'text',
                                    placeholder: this.t('scope_placeholder'),
                                    value: this.editScope,
                                    oninput: (e: any) => { this.editScope = e.target.value; },
                                }),
                            ]),
                            m('div', { className: 'expression-editor-field expression-editor-field--readonly' }, [
                                m('span', { className: 'expression-editor-label' }, this.t('table.evidence')),
                                m('div', { className: 'expression-evidence' },
                                    item.evidence && item.evidence.quote
                                        ? '「' + item.evidence.quote + '」' + (item.evidence.source ? '（来源：' + this.sourceLabel(item.evidence.source) + '）' : '')
                                        : this.t('no_evidence')),
                            ]),
                            m('div', { className: 'expression-editor-field expression-editor-field--readonly' }, [
                                m('span', { className: 'expression-editor-label' }, this.t('table.usage')),
                                m('div', { className: 'expression-evidence' }, String(item.use_count) + ' ' + this.t('usage_times')),
                            ]),
                        ]),
                    ]),
                ])
                : null,
        ];

        return rows;
    }

    renderRowActions(item: any) {
        const buttons: any[] = [
            m('button', {
                className: 'Button Button--secondary',
                onclick: () => this.startEdit(item),
            }, this.t('action_edit')),
        ];

        if (item.status === 'pending' || item.status === 'disabled') {
            buttons.push(m('button', {
                className: 'Button Button--primary',
                onclick: () => this.perform(item.id, 'approve'),
            }, this.t('action_approve')));
        }

        if (item.status !== 'disabled') {
            buttons.push(m('button', {
                className: 'Button Button--secondary',
                onclick: () => this.perform(item.id, 'disable'),
            }, this.t('action_disable')));
        }

        buttons.push(m('button', {
            className: 'Button Button--danger',
            onclick: () => {
                if (confirm(this.t('confirm_delete'))) {
                    this.perform(item.id, 'delete');
                }
            },
        }, this.t('action_delete')));

        return buttons;
    }

    renderEditActions() {
        return [
            m('button', {
                className: 'Button Button--primary' + (this.saving ? ' Button--loading' : ''),
                disabled: this.saving,
                onclick: () => this.saveEdit(),
            }, this.t('action_save')),
            m('button', {
                className: 'Button Button--secondary',
                disabled: this.saving,
                onclick: () => { this.editingId = null; },
            }, this.t('action_cancel')),
        ];
    }

    renderPagination() {
        const totalPages = Math.max(1, Math.ceil(this.total / this.limit));
        const canPrev = this.page > 1;
        const canNext = this.page < totalPages;

        return m('div', { className: 'memory-pagination' }, [
            m('button', {
                className: 'Button Button--secondary' + (canPrev ? '' : ' disabled'),
                disabled: !canPrev || this.loading,
                onclick: () => this.load(this.page - 1),
            }, '‹'),
            m('span', { className: 'memory-page-info' }, this.page + ' / ' + totalPages),
            m('button', {
                className: 'Button Button--secondary' + (canNext ? '' : ' disabled'),
                disabled: !canNext || this.loading,
                onclick: () => this.load(this.page + 1),
            }, '›'),
        ]);
    }

    load(page = 1) {
        this.loading = true;
        this.error = null;
        m.redraw();

        app.request({
            method: 'GET',
            url: app.forum.attribute('apiUrl') + '/zai-bot/expressions',
            params: {
                page,
                limit: this.limit,
                status: this.status,
                q: this.q || undefined,
            },
        })
            .then((data: any) => {
                this.items = data.items || [];
                this.total = data.total || 0;
                this.page = data.page || 1;
                this.counts = data.counts || this.counts;
                this.loading = false;
                m.redraw();
            })
            .catch((e: any) => {
                this.loading = false;
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    startEdit(item: any) {
        this.editingId = item.id;
        this.editName = item.name;
        this.editSituation = item.situation || '';
        this.editTemplate = item.template;
        this.editSyntax = item.syntax || '';
        this.editTags = (item.recall_tags || []).join('、');
        const scope = item.scope || {};
        const parts: string[] = [];
        if (scope.channels && scope.channels.length) parts.push('channels:' + scope.channels.join(','));
        if (scope.users && scope.users.length) parts.push('users:' + scope.users.join(','));
        if (scope.discussions && scope.discussions.length) parts.push('discussions:' + scope.discussions.join(','));
        this.editScope = parts.join('; ');
        m.redraw();
    }

    saveEdit() {
        if (!this.editingId) return;
        const id = this.editingId;
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/expressions/' + id,
            body: {
                name: this.editName,
                situation: this.editSituation,
                template: this.editTemplate,
                syntax: this.editSyntax,
                recall_tags: this.editTags.split(/[、,，]/).map((s: string) => s.trim()).filter(Boolean),
                scope: this.parseScope(this.editScope),
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

    parseScope(text: string) {
        const scope: any = {};
        for (const segment of text.split(';')) {
            const m = segment.trim().match(/^(channels|users|discussions):(.+)$/);
            if (m) {
                scope[m[1]] = m[2].split(',').map((s: string) => s.trim()).filter(Boolean);
            }
        }
        return scope;
    }

    perform(id: number, action: string) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/expressions/' + id,
            body: { action },
        })
            .then(() => {
                this.saving = false;
                this.load(this.page);
            })
            .catch((e: any) => {
                this.saving = false;
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    formatList(list: string[]) {
        return list && list.length > 0 ? list.join('、') : null;
    }

    sourceLabel(source: string) {
        const key = 'source_' + source;
        const label = this.t(key);
        return label === key ? source : label;
    }

    t(key: string, params?: any) {
        return app.translator.trans('zephyrisle-flarum-zai-bot.admin.expressions.' + key, params);
    }
}
