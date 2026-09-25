#!/usr/bin/env python3
"""Background translator for vue-i18n locale files (resumable, batched).

Reads the authoritative en.json, translates every key to the target
language via the public Google Translate dict-chrome endpoint in batches
(newline-joined, newline-split), and writes <lang>.json with English
fallback for untranslatable keys.

Resumable: per-language progress is kept in storage/i18n-progress/.
Safe to re-run; skips keys already translated.

Usage: python3 scripts/translate_locales.py [lang ...]
"""
import json
import os
import re
import sys
import fcntl
import time
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALES = os.path.join(ROOT, 'resources', 'js', 'i18n', 'locales')
STATE_DIR = os.path.join(ROOT, 'storage', 'i18n-progress')
LOCK_FILE = os.path.join(STATE_DIR, '.lock')


def acquire_lock():
    """Single-instance guard: only one translator process may run at a time."""
    os.makedirs(STATE_DIR, exist_ok=True)
    lock_fd = open(LOCK_FILE, 'w')
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        print('Another translator instance is running; exiting.', flush=True)
        sys.exit(0)
    return lock_fd

SUPPORTED = [
    'en', 'bn', 'ur', 'hi', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ru',
    'zh-CN', 'zh-TW', 'ja', 'ko', 'tr', 'nl', 'pl', 'sv', 'th', 'vi',
    'id', 'ms', 'tl', 'uk', 'ro', 'el', 'cs', 'hu', 'fi', 'da', 'no',
    'he', 'fa', 'ta', 'te', 'mr', 'gu', 'sw', 'am', 'my', 'km',
]

# Keys that must never be machine-translated (brands, codes, values, or
# strings that would corrupt identifiers if translated).
# NOTE: Only genuinely untranslatable values belong here (brand names and
# symbols). User-visible labels must be translated in every locale.
KEEP_EN = {
    'common.of', 'admin.stripe', 'admin.paypal',
}

BATCH = 16
RETRY_DELAY = 3.0
MAX_RETRIES = 8


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
    """Translate a batch of strings, preserving order via newline splitting."""
    joined = '\n'.join(texts)
    q = urllib.parse.quote(joined)
    url = (
        f'https://clients5.google.com/translate_a/t?client=dict-chrome-ex'
        f'&sl=en&tl={target}&q={q}'
    )
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    last_err = None
    for attempt in range(MAX_RETRIES):
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                data = json.loads(resp.read().decode('utf-8'))
            if isinstance(data, list) and data and isinstance(data[0], str):
                out = data[0].split('\n')
                if len(out) == len(texts):
                    return out
                return None
            return None
        except Exception as err:
            last_err = err
            time.sleep(RETRY_DELAY * (attempt + 1))
    raise last_err


_placeholder_re = re.compile(r'(\{[a-zA-Z0-9_]+\}|\[\[[^\]]+\]\])')


def split_tokens(text):
    """Split a string into (is_placeholder, token) preserving order."""
    parts = _placeholder_re.split(text)
    out = []
    for idx, part in enumerate(parts):
        out.append((idx % 2 == 1, part))
    return out


def main():
    lock_fd = acquire_lock()
    langs = sys.argv[1:] or [l for l in SUPPORTED if l != 'en']
    os.makedirs(STATE_DIR, exist_ok=True)
    os.makedirs(LOCALES, exist_ok=True)

    with open(os.path.join(LOCALES, 'en.json'), encoding='utf-8') as f:
        en_flat = flat(json.load(f))

    print(f'English source: {len(en_flat)} keys', flush=True)

    for lang in langs:
        state_path = os.path.join(STATE_DIR, f'{lang}.json')
        os.makedirs(os.path.dirname(state_path), exist_ok=True)
        if os.path.exists(state_path):
            with open(state_path, encoding='utf-8') as f:
                state = json.load(f)
        else:
            state = {'done': {}, 'retries': 0}

        todo = {k: v for k, v in en_flat.items() if k not in state['done'] and k not in KEEP_EN}
        print(f'[{lang}] {len(todo)} remaining of {len(en_flat)}', flush=True)

        keys = list(todo.keys())
        for i in range(0, len(keys), BATCH):
            batch_keys = keys[i:i + BATCH]

            # Flatten: for each key, list of (token_index, is_placeholder, token)
            key_tokens = {k: split_tokens(todo[k]) for k in batch_keys}
            # Positions of plain tokens in order
            plain_pos = []  # list of (key, token_index)
            for k in batch_keys:
                for ti, (is_ph, tok) in enumerate(key_tokens[k]):
                    if not is_ph:
                        plain_pos.append((k, ti))

            plain_texts = [key_tokens[k][ti][1] for (k, ti) in plain_pos]

            translated_plain = {}
            if plain_texts:
                res = translate_batch(plain_texts, lang)
                if res is None:
                    # Batch failed — translate individually with English fallback
                    for (k, ti), text in zip(plain_pos, plain_texts):
                        try:
                            one = translate_batch([text], lang)
                            translated_plain[(k, ti)] = one[0] if one else text
                        except Exception:
                            translated_plain[(k, ti)] = text
                else:
                    for (k, ti), tr in zip(plain_pos, res):
                        translated_plain[(k, ti)] = tr

            for k in batch_keys:
                rebuilt = []
                for ti, (is_ph, tok) in enumerate(key_tokens[k]):
                    if is_ph:
                        rebuilt.append(tok)
                    else:
                        rebuilt.append(translated_plain.get((k, ti), tok))
                state['done'][k] = ''.join(rebuilt)

            if (i // BATCH) % 4 == 0:
                print(f'  {len(state["done"])}/{len(en_flat)}', flush=True)
                with open(state_path, 'w', encoding='utf-8') as f:
                    json.dump(state, f, ensure_ascii=False)
            time.sleep(0.25)

        # Write locale file from state (English fallback for unfinished keys)
        merged = {k: state['done'].get(k, v) for k, v in en_flat.items()}
        with open(os.path.join(LOCALES, f'{lang}.json'), 'w', encoding='utf-8') as f:
            json.dump(nest(merged), f, ensure_ascii=False, indent=4)
            f.write('\n')
        with open(state_path, 'w', encoding='utf-8') as f:
            json.dump(state, f, ensure_ascii=False)
        print(f'[{lang}] wrote {lang}.json ({len(state["done"])}/{len(en_flat)} translated)', flush=True)


if __name__ == '__main__':
    main()