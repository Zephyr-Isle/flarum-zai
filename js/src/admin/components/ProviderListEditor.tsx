import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import app from 'flarum/admin/app';

interface ProviderEntry {
    name: string;
    api_url: string;
    api_key: string;
    weight: number;
}

export default class ProviderListEditor extends Component {
    providers: ProviderEntry[] = [];
    showKey: Record<number, boolean> = {};

    oninit(vnode: any) {
        super.oninit(vnode);
        const saved = app.data.settings['flarum-zai-bot.provider_list'];
        try {
            this.providers = saved ? JSON.parse(saved) : [];
        } catch {
            this.providers = [];
        }
        if (!this.providers.length) {
            this.providers.push({ name: 'openai', api_url: 'https://api.openai.com/v1', api_key: '', weight: 10 });
        }
    }

    view() {
        return m('div', { className: 'ProviderListEditor', style: 'margin-bottom:16px;padding:12px;border:1px solid #e0e0e0;border-radius:6px;' }, [
            m('h4', { style: 'margin-top:0;' }, 'AI 提供者'),
            this.providers.map((p, i) => this.providerRow(p, i)),
            m(Button, {
                className: 'Button Button--primary',
                onclick: () => {
                    this.providers.push({ name: '', api_url: 'https://api.openai.com/v1', api_key: '', weight: 10 });
                    this.save();
                },
                icon: 'fas fa-plus',
            }, '添加提供者'),
        ]);
    }

    providerRow(p: ProviderEntry, i: number) {
        return m('div', {
            style: 'display:flex;gap:8px;margin-bottom:8px;align-items:flex-start;flex-wrap:wrap;padding:8px;background:#f8f8f8;border-radius:4px;',
        }, [
            m('div', { style: 'flex:1;min-width:120px;' }, [
                m('label', { style: 'display:block;font-size:12px;color:#666;' }, '名称'),
                m('input', {
                    className: 'FormControl',
                    type: 'text',
                    value: p.name,
                    placeholder: 'openai',
                    oninput: (e: InputEvent) => {
                        p.name = (e.target as HTMLInputElement).value;
                        this.save();
                    },
                }),
            ]),
            m('div', { style: 'flex:2;min-width:200px;' }, [
                m('label', { style: 'display:block;font-size:12px;color:#666;' }, 'API 地址'),
                m('input', {
                    className: 'FormControl',
                    type: 'text',
                    value: p.api_url,
                    placeholder: 'https://api.openai.com/v1',
                    oninput: (e: InputEvent) => {
                        p.api_url = (e.target as HTMLInputElement).value;
                        this.save();
                    },
                }),
            ]),
            m('div', { style: 'flex:2;min-width:150px;' }, [
                m('label', { style: 'display:block;font-size:12px;color:#666;' }, 'API 密钥'),
                m('div', { style: 'display:flex;gap:4px;' }, [
                    m('input', {
                        className: 'FormControl',
                        type: this.showKey[i] ? 'text' : 'password',
                        value: p.api_key,
                        placeholder: 'sk-...',
                        oninput: (e: InputEvent) => {
                            p.api_key = (e.target as HTMLInputElement).value;
                            this.save();
                        },
                    }),
                    m(Button, {
                        className: 'Button Button--icon',
                        icon: this.showKey[i] ? 'fas fa-eye-slash' : 'fas fa-eye',
                        onclick: () => {
                            this.showKey[i] = !this.showKey[i];
                            m.redraw();
                        },
                    }),
                ]),
            ]),
            m('div', { style: 'width:70px;' }, [
                m('label', { style: 'display:block;font-size:12px;color:#666;' }, '权重'),
                m('input', {
                    className: 'FormControl',
                    type: 'number',
                    value: String(p.weight),
                    min: 1,
                    max: 100,
                    oninput: (e: InputEvent) => {
                        p.weight = parseInt((e.target as HTMLInputElement).value) || 1;
                        this.save();
                    },
                }),
            ]),
            m('div', { style: 'display:flex;align-items:flex-end;' }, [
                m(Button, {
                    className: 'Button Button--danger Button--icon',
                    icon: 'fas fa-times',
                    onclick: () => {
                        this.providers.splice(i, 1);
                        this.save();
                    },
                }),
            ]),
        ]);
    }

    save() {
        app.data.settings['flarum-zai-bot.provider_list'] = JSON.stringify(this.providers);
        m.redraw();
    }
}
