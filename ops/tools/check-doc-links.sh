#!/usr/bin/env bash
# Check relative markdown links and backticked repo paths in .md files.
# Usage: ops/tools/check-doc-links.sh [file.md ...]
# No args: the .md files changed vs origin/develop. Prints one summary line; exits 1 on failures.
set -u
root=$(git rev-parse --show-toplevel) || exit 2
cd "$root"
if [ $# -gt 0 ]; then files=("$@"); else
  mapfile -t files < <(git diff --name-only --diff-filter=d origin/develop...HEAD -- '*.md'; git diff --name-only --diff-filter=d -- '*.md')
fi
n=0; bad=0; seen=" "
for f in "${files[@]}"; do
  case " $seen " in *" $f "*) continue;; esac; seen="$seen $f "
  [ -f "$f" ] || { echo "MISSING FILE $f" >&2; bad=$((bad+1)); continue; }
  dir=$(dirname "$f")
  # markdown links (relative only), resolved from the file's directory
  while IFS= read -r l; do
    l=${l%%#*}; [ -z "$l" ] && continue
    case $l in *://*|mailto:*|/*|*'<'*) continue;; esac
    n=$((n+1)); [ -e "$dir/$l" ] || { echo "BROKEN LINK $f -> $l" >&2; bad=$((bad+1)); }
  done < <(grep -oE '\]\([^) ]+\)' "$f" | sed -E 's/^\]\(//; s/\)$//')
  # backticked paths with a slash (MIME types, packages, gitignored runtime paths skipped), resolved from the repo root (or file dir for ../)
  while IFS= read -r p; do
    case $p in *'<'*|*'*'*|*...*|/*|~*|*://*|-*|*' '*|*"'"*|@*|text/*|application/*|image/*) continue;; esac
    p=${p%%:[0-9~]*}
    case $p in */*) ;; *) continue;; esac   # bare file name, not a repo path
    git check-ignore -q "$p" 2>/dev/null && continue   # runtime/ignored path (storage/, vendor/ ...)
    n=$((n+1))
    if [ -e "$p" ] || [ -e "$dir/$p" ]; then :; else echo "BROKEN PATH $f -> $p" >&2; bad=$((bad+1)); fi
  done < <(grep -oE '`[^`]*/[^`]*`' "$f" | tr -d '`')
done
echo "doc-links: ${#files[@]} files, $n refs, $bad broken"
[ "$bad" -eq 0 ]
