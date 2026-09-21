#!/usr/bin/env python3
"""
Does each in-project import name the package the symbol actually lives in?

importlint answers "is this symbol imported at all", which is a different
question and the reason a build broke: WholesaleScreen imported
com.madeforu.sales.data.ApiResult, ApiResult was imported, the lint was
happy, and Kotlin was not -- ApiResult lives in .core. The symbol was
there, the package was wrong, and nothing in the checks looked at the
package.

Only com.madeforu.sales.* imports are checked. Library packages are not
indexed here and guessing at them would produce noise, which is how a
check stops being read.
"""
import re
import sys
import collections
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / 'app/src/main/java'

DECL = re.compile(
    r'^(?:@\w+(?:\([^)]*\))?\s*)*'
    r'(?:public\s+|internal\s+|private\s+)?'
    r'(?:sealed\s+|data\s+|abstract\s+|open\s+|value\s+|inline\s+)*'
    r'(?:class|interface|object|enum class|fun|val|var|typealias)\s+'
    r'(?:<[^>]+>\s+)?'
    r'(?:[\w.<>?]+\.)?'          # an extension receiver, e.g. LazyListScope.
    r'(\w+)',
    re.M,
)


def main() -> int:
    # symbol -> the packages that declare it
    where = collections.defaultdict(set)
    files = sorted(ROOT.rglob('*.kt'))
    for f in files:
        src = f.read_text(encoding='utf-8')
        pkg = re.search(r'^package\s+([\w.]+)', src, re.M)
        if not pkg:
            continue
        for m in DECL.finditer(src):
            where[m.group(1)].add(pkg.group(1))

    problems = 0
    for f in files:
        src = f.read_text(encoding='utf-8')
        for n, line in enumerate(src.split('\n'), 1):
            m = re.match(r'import\s+(com\.madeforu\.sales\.[\w.]+)', line)
            if not m:
                continue
            full = m.group(1)
            pkg, _, sym = full.rpartition('.')
            if sym not in where:
                continue            # not a top-level declaration we indexed
            if pkg not in where[sym]:
                rel = f.relative_to(ROOT)
                print(f'  ./{rel}:{n}  imports {full}')
                print(f'      but {sym} is declared in '
                      f'{", ".join(sorted(where[sym]))}')
                problems += 1

    print('package lint: clean' if not problems
          else f'package lint: {problems} problem(s)')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
