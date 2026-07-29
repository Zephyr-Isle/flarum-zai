import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import app from 'flarum/admin/app';

interface Schedule {
    active_hours?: { start: number; end: number };
    weekdays?: number[];
    active_chance?: number;
}

interface AccountEntry {
    username: string;
    display_name?: string;
    personality: string;
    schedule?: Schedule | null;
    custom_prompt?: string;
    weight?: number;
}

const PERSONALITY_OPTIONS: Record<string, string> = {
    friendly: '友善 (Friendly)',
    tsundere: '傲娇 (Tsundere)',
    loli: '萝莉 (Loli)',
    cool: '高冷 (Cool)',
    student: '学生 (Student)',
    elder: '前辈 (Elder)',
    tech: '技术宅 (Tech)',
};

const DAY_LABELS: Record<number, string> = {
    1: '一', 2: '二', 3: '三', 4: '四', 5: '五', 6: '六', 7: '日',
};

export default class AccountListEditor extends Component {
    accounts: AccountEntry[] = [];

    oninit(vnode: any) {
        super.oninit(vnode);
        const saved = app.data.settings['flarum-zai-bot.accounts'];
        try {
            this.accounts = saved ? JSON.parse(saved) : [];
        } catch {
            this.accounts = [];
        }
        if (!this.accounts.length) {
            this.accounts.push({ username: 'AIGirl', personality: 'friendly' });
        }
    }

    view() {
        return m('div', { className: 'AccountListEditor', style: 'margin-bottom:16px;padding:12px;border:1px solid #e0e0e0;border-radius:6px;' }, [
            m('h4', { style: 'margin-top:0;' }, '机器人账号'),
            this.accounts.map((a, i) => this.accountRow(a, i)),
            m(Button, {
                className: 'Button Button--primary',
                onclick: () => {
                    this.accounts.push({ username: '', personality: 'friendly' });
                    this.save();
                },
                icon: 'fas fa-plus',
            }, '添加账号'),
        ]);
    }

    accountRow(a: AccountEntry, i: number) {
        const sched = a.schedule || {};
        const activeHours = sched.active_hours || { start: 8, end: 23 };
        const weekdays = sched.weekdays || [1, 2, 3, 4, 5, 6, 7];

        return m('div', {
            style: 'margin-bottom:12px;padding:12px;background:#f8f8f8;border-radius:6px;border:1px solid #e8e8e8;',
        }, [
            m('div', { style: 'display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;align-items:flex-start;' }, [
                m('div', { style: 'flex:1;min-width:130px;' }, [
                    m('label', { style: 'display:block;font-size:12px;color:#666;' }, '用户名'),
                    m('input', {
                        className: 'FormControl',
                        type: 'text',
                        value: a.username,
                        placeholder: 'AIGirl',
                        oninput: (e: InputEvent) => {
                            a.username = (e.target as HTMLInputElement).value;
                            this.save();
                        },
                    }),
                ]),
                m('div', { style: 'flex:1;min-width:130px;' }, [
                    m('label', { style: 'display:block;font-size:12px;color:#666;' }, '显示名称（可选）'),
                    m('input', {
                        className: 'FormControl',
                        type: 'text',
                        value: a.display_name || '',
                        placeholder: 'Yuki',
                        oninput: (e: InputEvent) => {
                            a.display_name = (e.target as HTMLInputElement).value || undefined;
                            this.save();
                        },
                    }),
                ]),
                m('div', { style: 'flex:1;min-width:130px;' }, [
                    m('label', { style: 'display:block;font-size:12px;color:#666;' }, '性格'),
                    m(Select, {
                        value: a.personality,
                        options: PERSONALITY_OPTIONS,
                        onchange: (v: string) => {
                            a.personality = v;
                            this.save();
                        },
                    }),
                ]),
                m('div', { style: 'width:70px;' }, [
                    m('label', { style: 'display:block;font-size:12px;color:#666;' }, '权重'),
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        value: String(a.weight ?? 100),
                        min: 1,
                        max: 100,
                        oninput: (e: InputEvent) => {
                            a.weight = parseInt((e.target as HTMLInputElement).value) || 1;
                            this.save();
                        },
                    }),
                ]),
                m('div', { style: 'display:flex;align-items:flex-end;' }, [
                    m(Button, {
                        className: 'Button Button--danger Button--icon',
                        icon: 'fas fa-times',
                        onclick: () => {
                            this.accounts.splice(i, 1);
                            this.save();
                        },
                    }),
                ]),
            ]),
            m('div', { style: 'margin-bottom:8px;' }, [
                m('label', { style: 'display:block;font-size:12px;color:#666;margin-bottom:4px;' }, '自定义提示词（可选，留空则使用性格默认）'),
                m('textarea', {
                    className: 'FormControl',
                    style: 'width:100%;min-height:50px;',
                    value: a.custom_prompt || '',
                    placeholder: '自定义角色提示词...',
                    oninput: (e: InputEvent) => {
                        a.custom_prompt = (e.target as HTMLTextAreaElement).value || undefined;
                        this.save();
                    },
                }),
            ]),
            m('div', { style: 'display:flex;gap:16px;flex-wrap:wrap;align-items:center;' }, [
                m('div', { style: 'display:flex;gap:8px;align-items:center;' }, [
                    m('label', { style: 'font-size:12px;color:#666;' }, '活跃时段'),
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        style: 'width:60px;',
                        value: String(activeHours.start),
                        min: 0, max: 23,
                        title: '开始（时）',
                        oninput: (e: InputEvent) => {
                            const v = parseInt((e.target as HTMLInputElement).value) || 0;
                            if (!a.schedule) a.schedule = {};
                            a.schedule.active_hours = { start: v, end: activeHours.end };
                            this.save();
                        },
                    }),
                    m('span', { style: 'color:#666;' }, '—'),
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        style: 'width:60px;',
                        value: String(activeHours.end),
                        min: 0, max: 23,
                        title: '结束（时）',
                        oninput: (e: InputEvent) => {
                            const v = parseInt((e.target as HTMLInputElement).value) || 0;
                            if (!a.schedule) a.schedule = {};
                            a.schedule.active_hours = { start: activeHours.start, end: v };
                            this.save();
                        },
                    }),
                    m('span', { style: 'color:#999;font-size:11px;' }, '（留空全天活跃）'),
                ]),
                m('div', { style: 'display:flex;gap:4px;align-items:center;' }, [
                    m('label', { style: 'font-size:12px;color:#666;' }, '活跃日'),
                    [1, 2, 3, 4, 5, 6, 7].map(d => {
                        const selected = weekdays.includes(d);
                        return m('button', {
                            className: 'Button Button--' + (selected ? 'primary' : 'default'),
                            style: 'min-width:28px;padding:2px 6px;font-size:12px;',
                            onclick: () => {
                                if (!a.schedule) a.schedule = {};
                                const wds = a.schedule.weekdays || [1, 2, 3, 4, 5, 6, 7];
                                const idx = wds.indexOf(d);
                                if (idx >= 0) wds.splice(idx, 1);
                                else wds.push(d);
                                wds.sort();
                                a.schedule.weekdays = wds.length ? wds : undefined;
                                this.save();
                            },
                        }, DAY_LABELS[d]);
                    }),
                ]),
                m('div', { style: 'display:flex;gap:4px;align-items:center;' }, [
                    m('label', { style: 'font-size:12px;color:#666;' }, '活跃概率'),
                    m('input', {
                        className: 'FormControl',
                        type: 'number',
                        style: 'width:60px;',
                        value: String(a.schedule?.active_chance ?? ''),
                        min: 0, max: 100,
                        placeholder: '100',
                        oninput: (e: InputEvent) => {
                            const v = (e.target as HTMLInputElement).value;
                            if (!a.schedule) a.schedule = {};
                            a.schedule.active_chance = v ? parseInt(v) : undefined;
                            this.save();
                        },
                    }),
                    m('span', { style: 'color:#999;font-size:11px;' }, '%'),
                ]),
            ]),
        ]);
    }

    save() {
        app.data.settings['flarum-zai-bot.accounts'] = JSON.stringify(this.accounts);
        m.redraw();
    }
}
