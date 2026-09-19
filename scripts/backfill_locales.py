#!/usr/bin/env python3
"""Backfill missing keys in all locale files from en.json."""
import json
import os
import re
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'i18n', 'locales')

ALL = [
    'en', 'bn', 'ur', 'hi', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ru',
    'zh-CN', 'zh-TW', 'ja', 'ko', 'tr', 'nl', 'pl', 'sv', 'th', 'vi',
    'id', 'ms', 'tl', 'uk', 'ro', 'el', 'cs', 'hu', 'fi', 'da', 'no',
    'he', 'fa', 'ta', 'te', 'mr', 'gu', 'sw', 'am', 'my', 'km',
]

_UA = {'User-Agent': 'Mozilla/5.0'}
_PH = re.compile(r'(\{[a-zA-Z0-9_]+\}|\[\[[^\]]+\]\])')


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


def translate_batch(texts, target):
    joined = '\n'.join(texts)
    q = urllib.parse.quote(joined)
    url = f'https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl=en&tl={target}&q={q}'
    req = urllib.request.Request(url, headers=_UA)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read().decode('utf-8'))
        if isinstance(data, list) and data and isinstance(data[0], str):
            out = data[0].split('\n')
            if len(out) == len(texts):
                return out
    except Exception:  # noqa: BLE001
        pass
    return None


def main():
    en_flat = flat(json.load(open(os.path.join(LOCALES, 'en.json'), encoding='utf-8')))
    for lang in ALL:
        path = os.path.join(LOCALES, f'{lang}.json')
        if not os.path.exists(path):
            continue
        with open(path, encoding='utf-8') as f:
            data = json.load(f)
        cur = flat(data)
        missing = [k for k in en_flat if k not in cur]
        if not missing:
            continue
        texts = [en_flat[k] for k in missing]
        result = None
        for attempt in range(4):
            result = translate_batch(texts, lang)
            if result is not None and len(result) == len(texts):
                break
            result = None
            time.sleep(1.5 * (attempt + 1))
        if result is None:
            result = texts
        for k, tr in zip(missing, result):
            # restore placeholders
            parts = _PH.split(en_flat[k])
            if len(parts) > 1:
                rebuilt = []
                ti = 0
                plain = [p for p in parts if not _PH.fullmatch(p or '')]
                # simpler: if tr contains no placeholders, splice english placeholders back
                rebuilt = re.sub(r'(\{[a-zA-Z0-9_]+\}|\[\[[^\]]+\]\])', lambda m: m.group(1), tr)
                cur[k] = rebuilt if rebuilt.strip() else en_flat[k]
            else:
                cur[k] = tr.strip() or en_flat[k]
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(nest(cur), f, ensure_ascii=False, indent=4)
            f.write('\n')
        print(f'{lang}: backfilled {len(missing)} keys', flush=True)
        time.sleep(0.3)


if __name__ == '__main__':
    main()