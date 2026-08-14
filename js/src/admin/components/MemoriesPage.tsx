import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

/**
 * 后台记忆管理页：列出（外部 pgvector 中的）记忆原子，
 * 支持关键词搜索、按用户过滤、包含已归档，以及重要度/TTL 编辑与归档/恢复/删除。
 * 记忆内容与向量为只读（避免文本与 embedding 不一致）。
 */
export default class MemoriesPage extends Component {
    memories: any[] = [];
    total = 0;
    page = 1;
    limit = 20;
    loading = true;
    error: string | null = null;
    available = false;

    q = '';
    user = '';
    includeArchived = false;

    editingId: number | null = null;
    editImportance = 0;
    editTtl: string = '';
    saving = false;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    view() {
        return m('div', { className: 'ZaiBot-memories' }, [
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

            !this.available
                ? m('div', { className: 'Alert Alert--error' }, this.t('memory_disabled'))
                : [
                    m('div', { className: 'memory-filters' }, [
                        m('input', {
                            className: 'FormControl',
                            type: 'text',
                            placeholder: this.t('filter_q_placeholder'),
                            value: this.q,
                            oninput: (e: any) => { this.q = e.target.value; },
                        }),
                        m('input', {
                            className: 'FormControl',
                            type: 'text',
                            placeholder: this.t('filter_user_placeholder'),
                            value: this.user,
                            oninput: (e: any) => { this.user = e.target.value; },
                        }),
                        m('label', { className: 'memory-filter-archived' }, [
                            m('input', {
                                type: 'checkbox',
                                checked: this.includeArchived,
                                onchange: (e: any) => { this.includeArchived = e.target.checked; },
                            }),
                            ' ',
                            this.t('filter_archived'),
                        ]),
                        m('button', {
                            className: 'Button Button--primary',
                            disabled: this.loading,
                            onclick: () => this.load(1),
                        }, this.t('filter_apply')),
                    ]),
                    this.loading && this.memories.length === 0
                        ? m('div', { className: 'memory-loading' }, this.t('loading'))
                        : null,
                    this.error
                        ? m('div', { className: 'Alert Alert--error' }, this.t('error', { error: this.error }))
                        : this.renderTable(),
                ],
        ]);
    }

    renderTable() {
        if (this.loading && this.memories.length === 0) {
            return null;
        }

        if (this.memories.length === 0) {
            return m('div', { className: 'memory-empty' }, this.t('no_memories'));
        }

        return m('div', { className: 'memory-table-wrap' }, [
            m('table', [
                m('thead', [
                    m('tr', [
                        m('th', this.t('table.user')),
                        m('th', this.t('table.content')),
                        m('th', this.t('table.importance')),
                        m('th', this.t('table.ttl')),
                        m('th', this.t('table.reinforce')),
                        m('th', this.t('table.created')),
                        m('th', this.t('table.status')),
                        m('th', this.t('table.actions')),
                    ]),
                ]),
                m('tbody', this.memories.map((mem) => this.renderRow(mem))),
            ]),
            this.renderPagination(),
        ]);
    }

    renderRow(mem: any) {
        const isEditing = this.editingId === mem.id;

        return m('tr', { className: mem.archived ? 'memory-row--archived' : '' }, [
            m('td', { className: 'memory-user' }, [
                mem.display_name,
                ' ',
                m('small', '@' + mem.username),
            ]),
            m('td', { className: 'memory-content-cell' }, [
                m('div', { className: 'memory-content' }, mem.content || ''),
                mem.source_text
                    ? m('div', { className: 'memory-source' }, this.t('table.source', { source: mem.source_text }))
                    : null,
            ]),
            isEditing
                ? m('td', [
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        min: 0,
                        max: 10,
                        value: this.editImportance,
                        oninput: (e: any) => { this.editImportance = parseInt(e.target.value, 10) || 0; },
                    }),
                ])
                : m('td', { className: 'memory-importance' }, String(mem.importance)),
            isEditing
                ? m('td', [
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        min: 0,
                        placeholder: this.t('ttl_placeholder'),
                        value: this.editTtl,
                        oninput: (e: any) => { this.editTtl = e.target.value; },
                    }),
                ])
                : m('td', { className: 'memory-ttl' }, [
                    mem.ttl_days ? mem.ttl_days + ' 天' : '-',
                    mem.expires_at ? m('div', { className: 'memory-expires' }, mem.expires_at) : null,
                ]),
            m('td', String(mem.reinforce_count)),
            m('td', { className: 'memory-created' }, mem.created_at || '-'),
            m('td', { className: 'memory-status' }, mem.archived ? this.t('status_archived') : this.t('status_active')),
            m('td', { className: 'memory-actions' }, isEditing ? this.renderEditActions(mem) : this.renderRowActions(mem)),
        ]);
    }

    renderRowActions(mem: any) {
        const buttons: any[] = [];

        buttons.push(m('button', {
            className: 'Button Button--secondary',
            onclick: () => this.startEdit(mem),
        }, this.t('action_edit')));

        if (mem.archived) {
            buttons.push(m('button', {
                className: 'Button Button--secondary',
                onclick: () => this.perform(mem.id, 'restore'),
            }, this.t('action_restore')));
        } else {
            buttons.push(m('button', {
                className: 'Button Button--secondary',
                onclick: () => this.perform(mem.id, 'archive'),
            }, this.t('action_archive')));
        }

        buttons.push(m('button', {
            className: 'Button Button--danger',
            onclick: () => {
                if (confirm(this.t('confirm_delete'))) {
                    this.perform(mem.id, 'delete');
                }
            },
        }, this.t('action_delete')));

        return buttons;
    }

    renderEditActions(mem: any) {
        return [
            m('button', {
                className: 'Button Button--primary' + (this.saving ? ' Button--loading' : ''),
                disabled: this.saving,
                onclick: () => this.saveEdit(mem),
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
            url: app.forum.attribute('apiUrl') + '/zai-bot/memories',
            params: {
                page,
                limit: this.limit,
                q: this.q || undefined,
                user: this.user || undefined,
                include_archived: this.includeArchived ? '1' : '0',
            },
        })
            .then((data: any) => {
                this.memories = data.items || [];
                this.total = data.total || 0;
                this.page = data.page || 1;
                this.available = data.available !== false;
                this.loading = false;
                m.redraw();
            })
            .catch((e: any) => {
                this.loading = false;
                this.error = e.response?.error || e.statusText || e.message || 'Unknown error';
                m.redraw();
            });
    }

    startEdit(mem: any) {
        this.editingId = mem.id;
        this.editImportance = mem.importance;
        this.editTtl = mem.ttl_days ? String(mem.ttl_days) : '';
        m.redraw();
    }

    saveEdit(mem: any) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/memories/' + mem.id,
            body: {
                importance: this.editImportance,
                ttl_days: this.editTtl !== '' ? parseInt(this.editTtl, 10) : undefined,
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

    perform(id: number, action: string) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/memories/' + id,
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

    t(key: string, params?: any) {
        return app.translator.trans('zephyrisle-flarum-zai-bot.admin.memories.' + key, params);
    }
}
