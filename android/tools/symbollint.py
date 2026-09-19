"""
Every capitalised name used as a call or a type must be resolvable:
imported, declared in this project, or Kotlin's own.

importlint only knows names this project declares, so a missing androidx
import is invisible to it — which is how nine of them reached a build in
one edit, and how OutlinedTextField's import came to be deleted while the
widget was still on screen.

Kotlin's implicit imports (kotlin.*, kotlin.collections.*, java.lang for
JVM) are whitelisted; anything else has to be spelled out.
"""
import os, re, sys

STDLIB = set("""
String Int Long Double Float Boolean Char Byte Short Unit Any Nothing Number
List MutableList Map MutableMap Set MutableSet Array Collection Iterable Sequence
Pair Triple Result Comparable CharSequence Regex Exception RuntimeException Error
Throwable IllegalArgumentException IllegalStateException NumberFormatException
IndexOutOfBoundsException UnsupportedOperationException Lazy Function
StringBuilder Thread Runnable Comparator Math System Character Integer Void Class
Deprecated Suppress JvmStatic JvmOverloads JvmField Throws OptIn Volatile Synchronized
IntArray LongArray DoubleArray FloatArray BooleanArray CharArray ByteArray ShortArray
ArrayList LinkedHashMap LinkedHashSet HashMap HashSet StringBuffer Locale
""".split())

files = {}
for root, _, names in os.walk('.'):
    for n in sorted(names):
        if n.endswith('.kt'):
            p = os.path.join(root, n)
            files[p] = open(p, encoding='utf-8').read()

# every name this project declares anywhere, at any nesting
project = set()
for src in files.values():
    for m in re.finditer(
            r'\b(?:fun|val|var|class|object|interface|typealias|enum class)\s+'
            r'(?:<[^>]*>\s*)?(?:[A-Za-z_][\w.]*\.)?([A-Za-z_]\w*)', src):
        project.add(m.group(1))

bad = []
for p, src in files.items():
    pkg = (re.search(r'^package\s+([\w.]+)', src, re.M) or [None, ''])[1]
    imported = set()
    wildcard = False
    for m in re.finditer(r'^import\s+([\w.]+)(?:\s+as\s+(\w+))?$', src, re.M):
        if m.group(1).endswith('.*'):
            wildcard = True
        imported.add(m.group(2) or m.group(1).rsplit('.', 1)[-1])
    if wildcard:
        continue                       # a star import can supply anything

    body = '\n'.join(l for l in src.split('\n') if not l.startswith(('import ', 'package ')))
    body = re.sub(r'"(?:[^"\\]|\\.)*"', '""', body)
    body = re.sub(r'/\*.*?\*/', '', body, flags=re.S)
    body = re.sub(r'//.*$', '', body, flags=re.M)

    own = set(re.findall(
        r'\b(?:fun|val|var|class|object|interface)\s+(?:<[^>]*>\s*)?'
        r'(?:[A-Za-z_][\w.]*\.)?([A-Za-z_]\w*)', body))

    # Generic parameters are declared in the angle brackets, not imported:
    # `fun <T> decode(...)` makes T a name only inside that function.
    for params in re.findall(r'\b(?:fun|class|interface)\s*<([^>]*)>', body):
        for q in params.split(','):
            bare = q.split(':')[0].strip().split()
            if bare:
                own.add(bare[-1])

    # Enum entries are declarations that look exactly like calls --
    # `TODAY("Today"),` inside an enum class body read as a call to TODAY.
    for m in re.finditer(r'\benum\s+class\s+\w+[^{]*\{(.*?)(?:;|\n\s*\})', body, re.S):
        own |= set(re.findall(r'^\s*([A-Z][\w]*)\s*[(,]', m.group(1), re.M))

    used = set(re.findall(r'(?<![.\w@])([A-Z]\w*)\s*[(<]', body))
    used |= set(re.findall(r'[:<,]\s*([A-Z]\w*)', body))
    used |= set(re.findall(r'(?<![.\w])([A-Z]\w*)\.', body))

    for name in sorted(used):
        if name in imported or name in own or name in STDLIB or name in project:
            continue
        line = next((i + 1 for i, l in enumerate(src.split('\n'))
                     if re.search(r'\b' + re.escape(name) + r'\b', l)
                     and not l.startswith('import')), 0)
        bad.append((p, line, name))

print('symbol lint:', ('%d problem(s)' % len(bad)) if bad else 'clean')
for p, line, name in bad:
    print('  %s:%d  %s is neither imported nor declared here' % (p, line, name))
sys.exit(1 if bad else 0)
