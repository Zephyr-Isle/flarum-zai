import app from 'flarum/admin/app';

export default class AffinitiesPage {
    affinities: any[] | null = null;
    loading = true;
    error: string | null = null;

    oninit() {
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
                this.error = 'Failed to load affinities: ' + (e.statusText || e.message || 'Unknown error');
                m.redraw();
            });
    }

    view() {
        return m('div', { className: 'ExtensionPage-settings' }, [
            m('h2', 'Bot Affinities'),
            m('p', 'User affinity scores for the AI bot. Scores increase with each interaction.'),
            this.loading
                ? m('p', 'Loading...')
                : this.error
                    ? m('div', { className: 'Alert Alert--error' }, this.error)
                    : this.renderTable(),
            m('br'),
            m('button', { className: 'Button', onclick: () => this.load() }, 'Refresh'),
        ]);
    }

    renderTable() {
        if (!this.affinities || this.affinities.length === 0) {
            return m('p', 'No affinities recorded yet.');
        }

        return m('table', { className: 'Table' }, [
            m('thead', [
                m('tr', [
                    m('th', 'User'),
                    m('th', 'Total Score'),
                    m('th', 'Chat Score'),
                    m('th', 'Forum Score'),
                    m('th', 'Interactions'),
                    m('th', 'Last Interaction'),
                ]),
            ]),
            m('tbody', this.affinities.map((aff: any) =>
                m('tr', [
                    m('td', aff.display_name + ' (@' + aff.username + ')'),
                    m('td', String(aff.total_score)),
                    m('td', String(aff.chat_score)),
                    m('td', String(aff.forum_score)),
                    m('td', String(aff.interaction_count)),
                    m('td', aff.last_interaction_at || '-'),
                ])
            )),
        ]);
    }
}
