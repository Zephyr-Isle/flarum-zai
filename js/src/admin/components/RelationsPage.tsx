import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

/**
 * 后台关系网管理页：维护每个用户的稳定身份、别名、社区档案备注、边界备注
 * 与待确认观察（可确认/驳回）。关系事实与表达学习（只保存"怎么说"）分离。
 */
export default class RelationsPage extends Component {
    relations: any[] = [];
    total = 0;
    page = 1;
    limit = 20;
    loading = true;
    error: string | null = null;

    q = '';

    editingId: number | null = null;
    editIdentity = '';
    editAliases = '';
    editGroupProfile = '';
    editBoundaries: string[] = [];
    saving = false;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.load();
    }

    view() {
        return m('div', { className: 'ZaiBot-memories ZaiBot-relations' }, [
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
                m('input', {
                    className: 'FormControl',
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

    renderTable() {
        if (this.loading && this.relations.length === 0) {
            return m('div', { className: 'memory-loading' }, this.t('loading'));
        }

        if (this.relations.length === 0) {
            return m('div', { className: 'memory-empty' }, this.t('no_relations'));
        }

        return m('div', { className: 'memory-table-wrap' }, [
            m('table', [
                m('thead', [
                    m('tr', [
                        m('th', this.t('table.user')),
                        m('th', this.t('table.identity')),
                        m('th', this.t('table.aliases')),
                        m('th', this.t('table.group_profile')),
                        m('th', this.t('table.boundaries')),
                        m('th', this.t('table.pending')),
                        m('th', this.t('table.actions')),
                    ]),
                ]),
                m('tbody', this.relations.map((rel) => this.renderRow(rel))),
            ]),
            this.renderPagination(),
        ]);
    }

    renderRow(rel: any) {
        const isEditing = this.editingId === rel.user_id;
        const pending = rel.pending_observations || [];

        return m('tr', {}, [
            m('td', { className: 'memory-user' }, [
                rel.display_name,
                ' ',
                m('small', '@' + rel.username),
            ]),
            m('td', { className: 'relation-cell' }, isEditing ? this.renderIdentityEditor() : (rel.identity || '-')),
            m('td', { className: 'relation-cell' }, isEditing ? this.renderAliasesEditor() : (this.formatList(rel.aliases) || '-')),
            m('td', { className: 'relation-cell' }, isEditing ? this.renderGroupProfileEditor() : (rel.group_profile || '-')),
            m('td', { className: 'relation-cell' }, isEditing ? this.renderBoundariesEditor() : (this.renderBoundaries(rel.boundaries))),
            m('td', { className: 'relation-cell' }, isEditing ? this.renderPendingEditor(rel, pending) : this.renderPending(pending)),
            m('td', { className: 'memory-actions' }, isEditing ? this.renderEditActions(rel) : [
                m('button', {
                    className: 'Button Button--secondary',
                    onclick: () => this.startEdit(rel),
                }, this.t('action_edit')),
            ]),
        ]);
    }

    renderIdentityEditor() {
        return m('textarea', {
            className: 'FormControl relation-textarea',
            rows: 2,
            placeholder: this.t('identity_placeholder'),
            value: this.editIdentity,
            oninput: (e: any) => { this.editIdentity = e.target.value; },
        });
    }

    renderAliasesEditor() {
        return m('input', {
            className: 'FormControl',
            type: 'text',
            placeholder: this.t('aliases_placeholder'),
            value: this.editAliases,
            oninput: (e: any) => { this.editAliases = e.target.value; },
        });
    }

    renderGroupProfileEditor() {
        return m('textarea', {
            className: 'FormControl relation-textarea',
            rows: 2,
            placeholder: this.t('group_profile_placeholder'),
            value: this.editGroupProfile,
            oninput: (e: any) => { this.editGroupProfile = e.target.value; },
        });
    }

    renderBoundariesEditor() {
        return [
            m('textarea', {
                className: 'FormControl relation-textarea',
                rows: 2,
                placeholder: this.t('boundaries_placeholder'),
                value: this.editBoundaries.join('\n'),
                oninput: (e: any) => { this.editBoundaries = e.target.value.split('\n').map((s: string) => s.trim()).filter(Boolean); },
            }),
            m('div', { className: 'relation-hint' }, this.t('boundaries_hint')),
        ];
    }

    renderPendingEditor(rel: any, pending: any[]) {
        if (pending.length === 0) {
            return m('span', { className: 'relation-muted' }, this.t('no_pending'));
        }

        return m('div', { className: 'relation-pending' }, pending.map((item, idx) => m('div', { className: 'relation-pending-item' }, [
            m('span', { className: 'relation-pending-text' }, item.observation),
            item.context ? m('small', { className: 'relation-muted' }, item.context) : null,
            m('span', { className: 'relation-pending-actions' }, [
                m('button', {
                    className: 'Button Button--primary Button--small',
                    onclick: () => this.perform(rel.user_id, 'confirm_observation', idx),
                }, this.t('action_confirm')),
                m('button', {
                    className: 'Button Button--danger Button--small',
                    onclick: () => this.perform(rel.user_id, 'reject_observation', idx),
                }, this.t('action_reject')),
            ]),
        ])));
    }

    renderBoundaries(boundaries: string[]) {
        if (!boundaries || boundaries.length === 0) {
            return m('span', { className: 'relation-muted' }, '-');
        }

        return m('div', { className: 'relation-boundary-list' }, boundaries.map((b) => m('div', { className: 'relation-boundary-item' }, b)));
    }

    renderPending(pending: any[]) {
        if (!pending || pending.length === 0) {
            return m('span', { className: 'relation-muted' }, '-');
        }

        return m('div', { className: 'relation-pending' }, pending.map((item) => m('div', { className: 'relation-pending-item relation-pending-item--static' }, item.observation)));
    }

    renderEditActions(rel: any) {
        return [
            m('button', {
                className: 'Button Button--primary' + (this.saving ? ' Button--loading' : ''),
                disabled: this.saving,
                onclick: () => this.saveEdit(rel),
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
            url: app.forum.attribute('apiUrl') + '/zai-bot/relations',
            params: {
                page,
                limit: this.limit,
                q: this.q || undefined,
            },
        })
            .then((data: any) => {
                this.relations = data.items || [];
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

    startEdit(rel: any) {
        this.editingId = rel.user_id;
        this.editIdentity = rel.identity || '';
        this.editAliases = (rel.aliases || []).join('、');
        this.editGroupProfile = rel.group_profile || '';
        this.editBoundaries = rel.boundaries || [];
        m.redraw();
    }

    saveEdit(rel: any) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/relations/' + rel.user_id,
            body: {
                identity: this.editIdentity,
                aliases: this.editAliases.split(/[、,，]/).map((s: string) => s.trim()).filter(Boolean),
                group_profile: this.editGroupProfile,
                boundaries: this.editBoundaries,
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

    perform(userId: number, action: string, index?: number) {
        this.saving = true;
        m.redraw();

        app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/zai-bot/relations/' + userId,
            body: { action, index },
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

    t(key: string, params?: any) {
        return app.translator.trans('zephyrisle-flarum-zai-bot.admin.relations.' + key, params);
    }
}
