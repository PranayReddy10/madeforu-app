"""
Catch "Unresolved reference X": a symbol used in one package that is
declared in another and never imported.

A parse check cannot see this — the file is perfectly well-formed — and
grepping for the name finds its declaration, which is what let SoldLine
through twice. This resolves properly: it maps every top-level
declaration in the project to its package, then checks each file's uses
against its own package plus its imports.
"""
import os, re, sys, collections

DECL = re.compile(
    r'^\s*(?:@\w+\s+)*'
    r'(?:public\s+|internal\s+|private\s+|abstract\s+|open\s+|sealed\s+|data\s+|value\s+|enum\s+|annotation\s+)*'
    r'(?:fun|val|const\s+val|var|class|object|interface|typealias)\s+'
    r'(?:<[^>]*>\s*)?'
    r'([A-Za-z_]\w*)'
)

files = {}
for root, _, names in os.walk('.'):
    for n in sorted(names):
        if n.endswith('.kt'):
            p = os.path.join(root, n)
            files[p] = open(p, encoding='utf-8').read()

# symbol -> set of packages that declare it at top level
declared = collections.defaultdict(set)
pkg_of = {}
private_to = collections.defaultdict(set)   # file-private declarations
for p, src in files.items():
    m = re.search(r'^package\s+([\w.]+)', src, re.M)
    pkg = m.group(1) if m else ''
    pkg_of[p] = pkg
    for line in src.split('\n'):
        if line[:1] not in (' ', '\t', ''):      # top level only
            d = DECL.match(line)
            if d:
                name = d.group(1)
                if re.match(r'^\s*private\s', line):
                    private_to[name].add(p)
                else:
                    declared[name].add(pkg)

problems = []
for p, src in files.items():
    pkg = pkg_of[p]
    imports = set(re.findall(r'^import\s+([\w.]+)', src, re.M))
    imported_names = {i.rsplit('.', 1)[-1] for i in imports}
    wildcards = {i[:-2] for i in imports if i.endswith('.*')}

    body = re.sub(r'^\s*(package|import)\s+.*$', '', src, flags=re.M)
    body = re.sub(r'"(?:[^"\\]|\\.)*"', '""', body)          # string literals
    body = re.sub(r'/\*.*?\*/', '', body, flags=re.S)        # block comments
    body = re.sub(r'//.*$', '', body, flags=re.M)            # line comments

    # A name being CALLED or dereferenced: Foo(), Foo<T>, Foo.bar
    used = set(re.findall(r'(?<![.\w])([A-Za-z_]\w*)\s*[(<.]', body))
    # A name in TYPE position: ': Foo', ': Foo?', 'List<Foo>', '<A, Foo>'.
    # Missed at first, which let PartnerDetail through: it appears only as
    # a parameter type, so it is never followed by '(' or '.'.
    used |= set(re.findall(r'[:<,]\s*([A-Z]\w*)', body))
    for name in sorted(used):
        if name not in declared:
            continue
        pkgs = declared[name]
        if pkg in pkgs:
            continue                      # same package, no import needed
        if name in imported_names:
            continue
        if any(q in wildcards for q in pkgs):
            continue
        if p in private_to.get(name, ()):
            continue
        line = next((i + 1 for i, l in enumerate(src.split('\n'))
                     if re.search(r'\b' + re.escape(name) + r'\s*[(<.]', l)
                     and not l.strip().startswith(('import', '//', '*'))), 0)
        problems.append((p, line, name, sorted(pkgs)))

print('import lint:', ('%d problem(s)' % len(problems)) if problems else 'clean')
for p, line, name, pkgs in problems:
    print('  %s:%d  unresolved %s  (declared in %s)' % (p, line, name, ', '.join(pkgs)))
sys.exit(1 if problems else 0)
