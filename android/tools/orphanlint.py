# The failure from last time: a composable written, never called, and a
# grep for its name matching only its own definition. This checks every
# @Composable for a USE somewhere other than the line that declares it.
import re, os, collections

defs = {}
for root, _, files in os.walk('.'):
    for f in files:
        if not f.endswith('.kt'): continue
        path = os.path.join(root, f)
        src = open(path, encoding='utf-8').read()
        lines = src.split('\n')
        for i, line in enumerate(lines):
            m = re.match(r'\s*(private\s+|internal\s+)?fun\s+([A-Z]\w*)\s*\(', line)
            if not m: continue
            # only composables / UI builders
            back = '\n'.join(lines[max(0, i - 6):i])
            if '@Composable' not in back: continue
            defs[m.group(2)] = (path, i + 1, '@Preview' in back)

bodies = {}
for root, _, files in os.walk('.'):
    for f in files:
        if f.endswith('.kt'):
            p = os.path.join(root, f)
            bodies[p] = open(p, encoding='utf-8').read()

orphans = []
for name, (path, line, preview) in sorted(defs.items()):
    uses = 0
    for p, src in bodies.items():
        for m in re.finditer(r'\b' + name + r'\b', src):
            # skip the declaration itself
            ln = src[:m.start()].count('\n') + 1
            if p == path and ln == line: continue
            uses += 1
    if uses == 0:
        orphans.append((name, path, line, preview))

print(f'{len(defs)} composables checked')
if not orphans:
    print('every one is called somewhere. none orphaned.')
for name, path, line, preview in orphans:
    print(f'  ORPHAN{" (@Preview - called by the IDE)" if preview else ""}: '
          f'{name}  {path}:{line}')
