#!/bin/sh
# The release version appears in three files and they must agree, or the
# app reports one build while serving another and "I uploaded it and
# nothing changed" starts all over again. Run this before every upload.
set -e
cd "$(dirname "$0")"

html=$(sed -n 's/.*app\.js?v=\([^"]*\)".*/\1/p' index.html | head -1)
css=$(sed  -n 's/.*app\.css?v=\([^"]*\)".*/\1/p' index.html | head -1)
js=$(sed   -n "s/^const BUILD = '\(.*\)';/\1/p" app.js | head -1)
sw=$(sed   -n "s/^const VERSION = '\(.*\)';/\1/p" sw.js | head -1)

echo "index.html  app.js?v= : $html"
echo "index.html app.css?v= : $css"
echo "app.js         BUILD  : $js"
echo "sw.js         VERSION : $sw"

if [ "$html" = "$css" ] && [ "$html" = "$js" ] && [ "$html" = "$sw" ] && [ -n "$html" ]; then
  echo "OK — all four agree on $html"
else
  echo "MISMATCH — fix these before uploading." >&2
  exit 1
fi
