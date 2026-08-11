import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Switch from 'flarum/common/components/Switch';
import icon from 'flarum/common/helpers/icon';
import Stream from 'flarum/common/utils/Stream';

interface Provider {
    name: string;
    api_url: string;
    api_keys: string;
    model: string;
    enabled: boolean;
}

interface ProvidersSettingsAttrs {
    stream: Stream<string>;
}

type RequiredField = 'api_url' | 'api_keys' | 'model';

/**
 * 供应商图形化配置编辑器。
 *
 * 直接读写 `flarum-zai-bot.providers` 的 JSON 值（通过 AdminPage 的 Stream），
 * 任何修改都会写回 Stream，使设置表单的“保存”按钮变为可点击，随表单一起保存。
 * 若检测到旧版 api_url / api_keys / model 设置且尚未配置供应商，会自动导入到编辑器中。
 */
export default class ProvidersSettings extends Component<ProvidersSettingsAttrs> {
    providers: Provider[] = [];
    migrated = false;
    touched: Record<string, boolean> = {};

    oninit(vnode: any) {
        super.oninit(vnode);

        // 任何初始化异常都不应拖垮整个设置区（供应商编辑器只是设置页的一部分）
        try {
            this.providers = this.parseProviders(this.attrs.stream());

            if (this.providers.length === 0) {
                const legacy = this.migrateFromLegacy();
                if (legacy.length > 0) {
                    this.providers = legacy;
                    this.migrated = true;
                    // 写回 Stream（挂载期间不重绘），让“保存”按钮立即可用，提示用户保存迁移后的配置
                    this.attrs.stream(this.serialize());
                }
            }
        } catch (e) {
            this.providers = [];
        }
    }

    t(key: string, params?: any) {
        return app.translator.trans('zephyrisle-flarum-zai-bot.admin.settings.' + key, params);
    }

    parseProviders(raw: string): Provider[] {
        if (!raw) return [];

        let decoded: any;
        try {
            decoded = JSON.parse(raw);
        } catch (e) {
            return [];
        }

        if (!Array.isArray(decoded)) return [];

        return decoded
            .filter((p: any) => p && typeof p === 'object')
            .map((p: any) => ({
                name: typeof p.name === 'string' ? p.name : '',
                api_url: typeof p.api_url === 'string' ? p.api_url : '',
                api_keys: typeof p.api_keys === 'string' ? p.api_keys : '',
                model: typeof p.model === 'string' ? p.model : '',
                enabled: p.enabled !== false,
            }));
    }

    /**
     * 旧版设置迁移：providers 为空且存在旧版 api_keys 时，将其导入为默认供应商。
     */
    migrateFromLegacy(): Provider[] {
        const keys = app.data.settings['flarum-zai-bot.api_keys'] || '';
        if (!keys.trim()) return [];

        return [
            {
                name: 'Default',
                api_url: app.data.settings['flarum-zai-bot.api_url'] || 'https://api.openai.com/v1',
                api_keys: keys,
                model: app.data.settings['flarum-zai-bot.model'] || 'gpt-4o-mini',
                enabled: true,
            },
        ];
    }

    serialize(): string {
        return JSON.stringify(
            this.providers.map((p) => ({
                name: p.name,
                api_url: p.api_url,
                api_keys: p.api_keys,
                model: p.model,
                enabled: p.enabled,
            }))
        );
    }

    persist() {
        this.attrs.stream(this.serialize());
        m.redraw();
    }

    addProvider() {
        this.providers.push({
            name: '',
            api_url: '',
            api_keys: '',
            model: 'gpt-4o-mini',
            enabled: true,
        });
        this.persist();
    }

    removeProvider(index: number) {
        this.providers.splice(index, 1);
        this.persist();
    }

    move(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= this.providers.length) return;

        const [provider] = this.providers.splice(index, 1);
        this.providers.splice(target, 0, provider);
        this.persist();
    }

    update(index: number, field: keyof Provider, value: any) {
        this.touch(index, field);
        this.providers[index][field] = value;
        this.persist();
    }

    touch(index: number, field: keyof Provider) {
        this.touched[index + ':' + field] = true;
    }

    isTouched(index: number, field: keyof Provider): boolean {
        return !!this.touched[index + ':' + field];
    }

    isValidUrl(url: string): boolean {
        return /^https?:\/\/.+/i.test(url.trim());
    }

    fieldError(p: Provider, field: RequiredField): string | null {
        switch (field) {
            case 'api_url':
                if (!p.api_url.trim()) return this.t('provider_url_required');
                if (!this.isValidUrl(p.api_url)) return this.t('provider_url_invalid');
                return null;
            case 'api_keys': {
                const keys = p.api_keys
                    .split(',')
                    .map((k) => k.trim())
                    .filter(Boolean);
                return keys.length ? null : this.t('provider_keys_required');
            }
            case 'model':
                return p.model.trim() ? null : this.t('provider_model_required');
        }
    }

    /**
     * 卡片是否显示错误状态（仅统计用户已接触过的字段）。
     */
    hasError(p: Provider, index: number): boolean {
        return (['api_url', 'api_keys', 'model'] as RequiredField[]).some(
            (field) => this.isTouched(index, field) && this.fieldError(p, field)
        );
    }

    renderField(
        index: number,
        field: RequiredField,
        label: string,
        value: string,
        placeholder: string,
        extra?: any
    ) {
        const error = this.isTouched(index, field) ? this.fieldError(this.providers[index], field) : null;

        return m('div', { className: 'ZaiBot-provider-field' }, [
            m('label', { className: 'ZaiBot-provider-field-label' }, label),
            m('input', {
                className: 'FormControl' + (error ? ' ZaiBot-provider-field--error' : ''),
                type: 'text',
                value,
                placeholder,
                oninput: (e: any) => this.update(index, field, e.target.value),
                onblur: () => this.touch(index, field),
                ...extra,
            }),
            error ? m('div', { className: 'ZaiBot-provider-field-error' }, error) : null,
        ]);
    }

    renderCard(p: Provider, index: number) {
        return m('div', { className: 'ZaiBot-provider-card' + (this.hasError(p, index) ? ' ZaiBot-provider-card--invalid' : '') }, [
            m('div', { className: 'ZaiBot-provider-card-header' }, [
                m('span', { className: 'ZaiBot-provider-index' }, String(index + 1)),
                m('input', {
                    className: 'FormControl ZaiBot-provider-name',
                    type: 'text',
                    value: p.name,
                    placeholder: this.t('provider_name_placeholder'),
                    oninput: (e: any) => this.update(index, 'name', e.target.value),
                }),
                m('div', { className: 'ZaiBot-provider-enabled' }, [
                    m(Switch, {
                        state: p.enabled,
                        onchange: (val: boolean) => this.update(index, 'enabled', val),
                    }, this.t('provider_enabled_label')),
                ]),
                m('div', { className: 'ZaiBot-provider-card-actions' }, [
                    m('button', {
                        className: 'Button Button--icon Button--secondary' + (index === 0 ? ' disabled' : ''),
                        title: this.t('provider_move_up'),
                        'aria-label': this.t('provider_move_up'),
                        disabled: index === 0,
                        onclick: () => this.move(index, -1),
                    }, icon('fas fa-arrow-up')),
                    m('button', {
                        className: 'Button Button--icon Button--secondary' + (index === this.providers.length - 1 ? ' disabled' : ''),
                        title: this.t('provider_move_down'),
                        'aria-label': this.t('provider_move_down'),
                        disabled: index === this.providers.length - 1,
                        onclick: () => this.move(index, 1),
                    }, icon('fas fa-arrow-down')),
                    m('button', {
                        className: 'Button Button--icon Button--danger',
                        title: this.t('provider_delete'),
                        'aria-label': this.t('provider_delete'),
                        onclick: () => this.removeProvider(index),
                    }, icon('fas fa-trash-alt')),
                ]),
            ]),
            m('div', { className: 'ZaiBot-provider-card-body' }, [
                this.renderField(index, 'api_url', this.t('provider_url_label'), p.api_url, this.t('provider_url_placeholder')),
                this.renderField(
                    index,
                    'api_keys',
                    this.t('provider_keys_label'),
                    p.api_keys,
                    this.t('provider_keys_placeholder'),
                    { spellcheck: false, autocapitalize: 'off', autocorrect: 'off' }
                ),
                this.renderField(index, 'model', this.t('provider_model_label'), p.model, this.t('provider_model_placeholder')),
            ]),
        ]);
    }

    view() {
        return m('div', { className: 'ZaiBot-providers' }, [
            m('div', { className: 'ZaiBot-providers-title' }, this.t('providers_label')),
            m('div', { className: 'helpText' }, this.t('providers_help')),
            this.migrated
                ? m('div', { className: 'ZaiBot-providers-migrated' }, [
                      icon('fas fa-info-circle'),
                      ' ',
                      this.t('providers_migrated'),
                  ])
                : null,
            this.providers.length === 0
                ? m('div', { className: 'ZaiBot-providers-empty' }, this.t('providers_empty'))
                : m('div', { className: 'ZaiBot-providers-list' }, this.providers.map((p, i) => this.renderCard(p, i))),
            m('button', { className: 'Button Button--primary', onclick: () => this.addProvider() }, [
                icon('fas fa-plus'),
                ' ',
                this.t('provider_add'),
            ]),
        ]);
    }
}
