"""
Catch what a parse check cannot: annotations that are syntactically valid
but semantically wrong, because an edit landed in the wrong place.

  1. the same annotation twice on one declaration  ("not repeatable")
  2. an annotation followed by a doc comment -- the giveaway that an
     insertion split an existing  /** doc */ @Composable fun X()  apart,
     silently stripping the annotation off whatever came after. That is
     exactly how SoldLine ended up with two @Composable and DetailRow
     with none.

Only annotations that sit ALONE on their line stack up for the next
declaration. `@SerialName("x") val y: Int` is a declaration, not a
pending annotation, and must not be treated as one.
"""
import os, re, sys

ANN = re.compile(r'^@(\w+)\s*(\([^)]*\))?\s*$')

bad = []
for root, _, files in os.walk('.'):
    for f in sorted(files):
        if not f.endswith('.kt'):
            continue
        path = os.path.join(root, f)
        lines = open(path, encoding='utf-8').read().split('\n')
        seen = []
        for i, raw in enumerate(lines):
            line = raw.strip()
            if not line:
                continue
            if line.startswith('@file:'):
                continue
            m = ANN.match(line)
            if m:
                if m.group(1) in seen:
                    bad.append((path, i + 1, 'repeated @' + m.group(1)))
                seen.append(m.group(1))
                continue
            if line.startswith('/**') or line.startswith('/*'):
                if seen:
                    bad.append((path, i + 1,
                                'doc comment after @' + seen[-1] +
                                ' — that annotation now binds to whatever follows the doc'))
                seen = []
                continue
            if line.startswith('*') or line.startswith('//'):
                continue
            seen = []
print('annotation lint:', ('%d problem(s)' % len(bad)) if bad else 'clean')
for path, line, why in bad:
    print('  %s:%d  %s' % (path, line, why))
sys.exit(1 if bad else 0)
