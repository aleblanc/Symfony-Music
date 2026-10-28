/*
 * Front-end translations. The page renders the `js.*` messages of its locale
 * (raw ICU MessageFormat, from translations/messages+intl-icu.*.yaml) as
 * #i18n-config; I18N.t(key, params) formats one of them. Supports the subset
 * the catalogues use: {name}, {n, number}, {n, plural, =0 {…} one {…} other {…}}
 * with #, {x, select, a {…} other {…}}. An unknown key is returned as is.
 */
(function () {
    let config = {};
    try {
        config = JSON.parse(document.getElementById('i18n-config')?.textContent || '{}');
    } catch {
        // Missing or broken config: keys are shown instead of texts.
    }
    const locale = config.locale || document.documentElement.lang || 'en';
    const messages = config.messages || {};
    const numbers = new Intl.NumberFormat(locale);
    const cardinal = new Intl.PluralRules(locale);
    const ordinal = new Intl.PluralRules(locale, { type: 'ordinal' });

    // Index of the "}" closing the "{" at `start`.
    function closing(s, start) {
        let depth = 0;
        for (let i = start; i < s.length; i++) {
            if (s[i] === '{') depth++;
            else if (s[i] === '}' && --depth === 0) return i;
        }
        return s.length;
    }

    function format(message, params, hash) {
        let out = '';
        for (let i = 0; i < message.length; i++) {
            const c = message[i];
            if (c === '{') {
                const end = closing(message, i);
                out += argument(message.slice(i + 1, end), params, hash);
                i = end;
            } else if (c === '#' && hash !== undefined) {
                out += hash;
            } else {
                out += c;
            }
        }
        return out;
    }

    function argument(body, params, hash) {
        const [name, type = '', ...rest] = body.split(',');
        const value = params[name.trim()];
        const kind = type.trim();
        if (kind === '') return value === undefined || value === null ? '' : String(value);
        if (kind === 'number') return numbers.format(Number(value));
        if (kind !== 'plural' && kind !== 'select' && kind !== 'selectordinal') return String(value ?? '');

        const options = {};
        const s = rest.join(',');
        let offset = 0;
        for (let i = 0; i < s.length;) {
            while (i < s.length && /\s/.test(s[i])) i++;
            const from = i;
            while (i < s.length && s[i] !== '{' && !/\s/.test(s[i])) i++;
            const key = s.slice(from, i);
            if (key.startsWith('offset:')) { offset = Number(key.slice(7)); continue; }
            while (i < s.length && /\s/.test(s[i])) i++;
            if (key === '' || s[i] !== '{') break;
            const end = closing(s, i);
            options[key] = s.slice(i + 1, end);
            i = end + 1;
        }
        if (kind === 'select') return format(options[String(value)] ?? options.other ?? '', params, hash);

        const n = Number(value);
        const rules = kind === 'plural' ? cardinal : ordinal;
        const chosen = options['=' + n] ?? options[rules.select(n - offset)] ?? options.other ?? '';
        return format(chosen, params, numbers.format(n - offset));
    }

    window.I18N = {
        locale,
        t(key, params = {}) {
            const message = messages[key];
            return message === undefined ? key : format(message, params);
        },
    };
})();
