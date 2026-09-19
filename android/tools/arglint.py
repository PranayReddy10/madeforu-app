"""
Catch a named argument that does not exist on the function being called,
and a required parameter that was never supplied.

This is the next thing after "unresolved reference" that a parse check
cannot see and a grep will not find: the file is well-formed, the symbol
resolves, and the call is still wrong. Renaming or adding a parameter
without updating every call site lands exactly here.

Only calls that use named arguments are checked, and only against
functions declared in this project.
"""
import os, re, sys

files = {}
for root, _, names in os.walk('.'):
    for n in sorted(names):
        if n.endswith('.kt'):
            p = os.path.join(root, n)
            files[p] = open(p, encoding='utf-8').read()

def split_params(text):
    """Top-level comma split, ignoring commas inside <>, (), {} and strings."""
    out, depth, cur, instr = [], 0, '', False
    i = 0
    while i < len(text):
        c = text[i]
        if instr:
            if c == '\\': cur += text[i:i+2]; i += 2; continue
            if c == '"': instr = False
            cur += c
        elif c == '"':
            instr = True; cur += c
        elif c in '([{':
            depth += 1; cur += c
        elif c in ')]}':
            depth -= 1; cur += c
        elif c == ',' and depth == 0:
            out.append(cur); cur = ''
        else:
            cur += c
        i += 1
    if cur.strip(): out.append(cur)
    return out

# name -> (params, required, file)
sigs = {}
for p, src in files.items():
    for m in re.finditer(r'^\s*(?:@\w+\s*)*(?:private\s+|internal\s+|public\s+)?fun\s+([A-Z]\w*)\s*\(',
                         src, re.M):
        name = m.group(1)
        i = src.index('(', m.start())
        depth, j = 0, i
        while j < len(src):
            if src[j] == '(': depth += 1
            elif src[j] == ')':
                depth -= 1
                if depth == 0: break
            j += 1
        raw = src[i+1:j]
        params, required = [], []
        for part in split_params(raw):
            pm = re.match(r'\s*(?:@\w+\s*)*(?:vararg\s+)?(\w+)\s*:', part)
            if not pm: continue
            params.append(pm.group(1))
            if '=' not in part.split(':', 1)[1]:
                required.append(pm.group(1))
        if name in sigs and sigs[name][0] != params:
            sigs[name] = None          # overloaded: skip, cannot tell which
        elif name not in sigs:
            sigs[name] = (params, required, p)

bad = []
for p, src in files.items():
    for m in re.finditer(r'(?<![.\w])([A-Z]\w*)\s*\(', src):
        name = m.group(1)
        sig = sigs.get(name)
        if not sig: continue
        params, required, decl = sig
        if not params: continue
        i = m.end() - 1
        depth, j = 0, i
        while j < len(src):
            if src[j] == '(': depth += 1
            elif src[j] == ')':
                depth -= 1
                if depth == 0: break
            j += 1
        if j >= len(src): continue
        args = split_params(src[i+1:j])
        named = [re.match(r'\s*(\w+)\s*=(?!=)', a) for a in args]
        named = [n.group(1) for n in named if n]
        if not named: continue                      # positional: not checked
        line = src[:m.start()].count('\n') + 1
        for n in named:
            if n not in params:
                bad.append((p, line, '%s has no parameter "%s" (it takes: %s)'
                            % (name, n, ', '.join(params))))
        if len(named) == len(args):                 # fully named call
            trailing = src[j+1:j+3].strip().startswith('{')
            for r in required:
                if r not in named and not trailing:
                    bad.append((p, line, '%s: required parameter "%s" not supplied' % (name, r)))

print('argument lint:', ('%d problem(s)' % len(bad)) if bad else 'clean')
for p, line, why in bad:
    print('  %s:%d  %s' % (p, line, why))
sys.exit(1 if bad else 0)
