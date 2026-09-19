#!/usr/bin/env python3
"""Background translator for vue-i18n locale files (resumable, offline-safe).

Reads the authoritative en.json, translates every key to the target
language via the public Google Translate dict-chrome endpoint, and writes
<lang>.json with English fallback for untranslatable keys.

Resumable: per-language progress is kept in storage/i18n-progress/.
Safe to re-run; skips keys already translated.

Usage: python3 scripts/translate_locales.py [lang ...]
"""
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'i18n', 'locales')
STATE_DIR = os.path.join(ROOT, 'storage', 'i18n-progress')

SUPPORTED = [
    'en', 'bn', 'ur', 'hi', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ru',
    'zh-CN', 'zh-TW', 'ja', 'ko', 'tr', 'nl', 'pl', 'sv', 'th', 'vi',
    'id', 'ms', 'tl', 'uk', 'ro', 'el', 'cs', 'hu', 'fi', 'da', 'no',
    'he', 'fa', 'ta', 'te', 'mr', 'gu', 'sw', 'am', 'my', 'km',
]

# Keys that must never be machine-translated (brands, codes, values, or
# strings that would corrupt identifiers if translated).
KEEP_EN = {
    'common.loading', 'common.required', 'common.page', 'common.of',
    'common.you', 'common.pay', 'language.loading', 'admin.stripe',
    'admin.paypal', 'billing.cvc', 'admin.paymentId', 'report.paymentId',
    'admin.allStatus', 'report.allStatus',
}


def flat(d, p=''):
    out = {}
    for k, v in d.items():
        path = f'{p}.{k}' if p else k
        if isinstance(v, dict):
            out.update(flat(v, path))
        else:
            out[path] = v
    return out


def nest(flat_dict):
    out = {}
    for path, value in flat_dict.items():
        parts = path.split('.')
        cur = out
        for part in parts[:-1]:
            cur = cur.setdefault(part, {})
        cur[parts[-1]] = value
    return out


def translate(text, target):
    if not text or not text.strip():
        return text
    q = urllib.parse.quote(text)
    url = f'https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl=en&tl={target}&q={q}'
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode('utf-8'))
        if isinstance(data, list) and data and isinstance(data[0], str):
            return data[0]
        return text
    except Exception:
        return None


_PLACEHOLDER_RE = re.compile(r'(\{[a-zA-Z0-9_]+\}|\[\[[^\]]+\]\])')


def translate_seg(seg, target):
    """Translate a text segment, preserving its original surrounding whitespace."""
    lead = len(seg) - len(seg.lstrip(' \t'))
    trail = len(seg) - len(seg.rstrip(' \t'))
    core = seg.strip()
    if not core:
        return seg
    out = translate(core, target)
    if out is None:
        return seg
    return ' ' * lead + out + ' ' * trail


def translate_with_placeholders(text, target):
    parts = _PLACEHOLDER_RE.split(text)
    out = []
    for idx, part in enumerate(parts):
        if idx % 2 == 1:
            out.append(part)
        else:
            out.append(translate_seg(part, target))
    return ''.join(out)


def main():
    langs = sys.argv[1:] or [l for l in SUPPORTED if l != 'en']
    os.makedirs(STATE_DIR, exist_ok=True)
    os.makedirs(LOCALES, exist_ok=True)

    with open(os.path.join(LOCALES, 'en.json'), encoding='utf-8') as f:
        en_flat = flat(json.load(f))
    print(f'English source: {len(en_flat)} keys', flush=True)

    for lang in langs:
        state_path = os.path.join(STATE_DIR, f'{lang}.json')
        if os.path.exists(state_path):
            with open(state_path, encoding='utf-8') as f:
                state = json.load(f)
        else:
            state = {'done': {}, 'retries': 0}

        todo = {k: v for k, v in en_flat.items() if k not in state['done'] and k not in KEEP_EN}
        print(f'[{lang}] {len(todo)} remaining of {len(en_flat)}', flush=True)

        for key, value in todo.items():
            translated = translate_with_placeholders(value, lang)
            if translated is None:
                state['retries'] += 1
                if state['retries'] >= 3:
                    state['done'][key] = value
                    state['retries'] = 0
                    continue
                time.sleep(2)
                break
            state['done'][key] = translated
            state['retries'] = 0
            if len(state['done']) % 25 == 0:
                with open(state_path, 'w', encoding='utf-8') as f:
                    json.dump(state, f, ensure_ascii=False)
            time.sleep(0.12)

        merged = {k: state['done'].get(k, v) for k, v in en_flat.items()}
        with open(os.path.join(LOCALES, f'{lang}.json'), 'w', encoding='utf-8') as f:
            json.dump(nest(merged), f, ensure_ascii=False, indent=4)
            f.write('\n')
        with open(state_path, 'w', encoding='utf-8') as f:
            json.dump(state, f, ensure_ascii=False)
        print(f'[{lang}] wrote {lang}.json ({len(state["done"])}/{len(en_flat)} translated)', flush=True)


if __name__ == '__main__':
    main()