#!/bin/sh
# Static checks for the Android sources.
#
# These exist because this app cannot be compiled in the environment it is
# developed in — dl.google.com, where the Android Gradle plugin and the
# Compose artifacts live, is blocked — so `./gradlew` is the first real
# compiler the code meets. Each check here covers a class of error that a
# parser accepts and a grep will not find, and each one was written after
# that exact error reached a build:
#
#   annlint     two annotations on one declaration, and an annotation
#               separated from its function by a doc comment (which
#               silently moves it onto whatever follows)
#   importlint  a symbol used in one package, declared in another, never
#               imported -- "Unresolved reference"
#   arglint     a named argument that the function does not have, or a
#               required parameter left out
#   orphanlint  a composable defined and never called anywhere
#
# Run from the android/ directory before pushing. Not a substitute for
# ./gradlew assembleDebug on a machine that can reach dl.google.com.
tools="$(cd "$(dirname "$0")" && pwd)"
src="$tools/../app/src/main/java"
cd "$src" || { echo "cannot find $src" >&2; exit 2; }

fail=0
for check in annlint importlint arglint; do
  python3 "$tools/$check.py" || fail=1
done
python3 "$tools/orphanlint.py" | grep -v '@Preview' || true

if [ "$fail" -eq 0 ]; then echo "all checks clean"; fi
exit $fail
