#!/usr/bin/env python3
"""
Is a literal `null` being passed where the parameter is not nullable?

Kotlin says "Null cannot be a value of a non-null type 'kotlin.String'"
and names a line and a column; nothing before the compiler did. This
reached a build as ProductThumb(row.item, null), where imageUrl is a
plain String with no question mark — IconTile, which ProductThumb wraps,
defaults it to "" instead, so the null looked reasonable and was not.

Only functions declared in this project are checked: their parameter
types are readable here, and library signatures are not. Both named
(foo = null) and positional nulls are covered.

A function name declared more than once with different signatures is
skipped rather than guessed at, which keeps this quiet enough to be
worth reading.
"""
import re
import sys
import collections
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'app/src/main/java'

# fun Name( ... ) — the parameter list, across lines.
FUNC = re.compile(r'\bfun\s+(?:<[^>]+>\s+)?(?:[\w.<>?]+\.)?(\w+)\s*\(', re.M)


def split_args(text):
    """Top-level comma split: ignores commas inside (), <>, [], strings."""
    out, depth, buf, quote = [], 0, '', None
    i = 0
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\':
                buf += text[i:i + 2]; i += 2; continue
            if c == quote:
                quote = None
        elif c in '"\'':
            quote = c
        elif c in '(<[':
            depth += 1
        elif c in ')>]':
            depth -= 1
        elif c == ',' and depth == 0:
            out.append(buf); buf = ''; i += 1; continue
        buf += c
        i += 1
    if buf.strip():
        out.append(buf)
    return out


def balanced(src, open_at):
    """Index just past the ) matching the ( at open_at, or None."""
    depth, i, quote = 0, open_at, None
    while i < len(src):
        c = src[i]
        if quote:
            if c == '\\':
                i += 2; continue
            if c == quote:
                quote = None
        elif c in '"\'':
            quote = c
        elif c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return None


def params_of(sig):
    """[(name, type_is_nullable)] from a parameter list."""
    out = []
    for raw in split_args(sig):
        p = raw.strip()
        if not p:
            continue
        p = re.sub(r'^(?:@\w+(?:\([^)]*\))?\s*)*', '', p)
        p = re.sub(r'^(?:val|var|vararg|crossinline|noinline)\s+', '', p)
        m = re.match(r'(\w+)\s*:\s*(.+)', p, re.S)
        if not m:
            continue
        name, typ = m.group(1), m.group(2)
        typ = typ.split('=')[0].strip()      # drop any default
        out.append((name, typ.endswith('?')))
    return out


def main() -> int:
    files = sorted(ROOT.rglob('*.kt'))

    # name -> [params]; a name seen with two different shapes is dropped.
    sigs, clashed = {}, set()
    for f in files:
        src = f.read_text(encoding='utf-8')
        for m in FUNC.finditer(src):
            close = balanced(src, m.end() - 1)
            if close is None:
                continue
            p = params_of(src[m.end():close])
            name = m.group(1)
            if name in sigs and sigs[name] != p:
                clashed.add(name)
            sigs[name] = p

    problems = 0
    for f in files:
        src = f.read_text(encoding='utf-8')
        for m in re.finditer(r'\b([A-Za-z_]\w*)\s*\(', src):
            name = m.group(1)
            if name not in sigs or name in clashed:
                continue
            if re.search(r'\bfun\s+(?:<[^>]+>\s+)?(?:[\w.<>?]+\.)?' + name + r'\s*$',
                         src[:m.end() - 1]):
                continue                     # the declaration itself
            close = balanced(src, m.end() - 1)
            if close is None:
                continue
            args = split_args(src[m.end():close])
            params = sigs[name]
            for pos, raw in enumerate(args):
                a = raw.strip()
                named = re.match(r'(\w+)\s*=\s*(.+)', a, re.S)
                if named:
                    value, pname = named.group(2).strip(), named.group(1)
                    hit = next((p for p in params if p[0] == pname), None)
                else:
                    value = a
                    hit = params[pos] if pos < len(params) else None
                if value != 'null' or hit is None or hit[1]:
                    continue
                line = src[:m.start()].count('\n') + 1
                rel = f.relative_to(ROOT)
                print(f'  ./{rel}:{line}  {name}(): null passed to '
                      f'`{hit[0]}`, which is not nullable')
                problems += 1

    print('null lint: clean' if not problems
          else f'null lint: {problems} problem(s)')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
