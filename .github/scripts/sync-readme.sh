#!/usr/bin/env bash
# Isolated, explicitly authorized contributor correction for SVN 2.0.0.
set -euo pipefail

read_tag() {
  awk 'tolower($0) ~ /^stable tag:[[:space:]]*/ { sub(/^[^:]*:[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print }' "$1"
}

release=2.0.0
if [[ ! "$release" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo 'Expected exactly one numeric Stable tag.' >&2
  exit 1
fi

checkout=$(mktemp -d)
trap 'rm -rf -- "$checkout"' EXIT
repository='https://plugins.svn.wordpress.org/bulk-password-reset'

# Do not create missing tags, and do not fetch or modify plugin code or assets.
svn checkout --non-interactive --depth empty "$repository" "$checkout"
svn update --non-interactive --depth empty "$checkout/trunk" "$checkout/tags"
svn update --non-interactive --depth empty "$checkout/tags/$release"
targets=("$checkout/trunk/readme.txt" "$checkout/tags/$release/readme.txt")
svn update --non-interactive "${targets[@]}"

for target in "${targets[@]}"; do
  if [[ ! -f "$target" ]] || [[ "$(read_tag "$target")" != "$release" ]]; then
    echo 'Both published readmes must already exist and name the same stable release as the local readme.' >&2
    exit 1
  fi
done

# Fail if either remote file changed since the reviewed two-line patch.
# Never copy master's 2.0.1 readme into the published 2.0.0 version.
python3 - "${targets[@]}" <<'PY'
import hashlib
from pathlib import Path
import sys

before = '90db171a6ffe34aadcb871a08dec8cf867cc1c459cef09b178fa13e59946e604'
after = '20908b2932039e3ae2c0edc5bdaa2c776ec902229d9e402b1efdbb247fd70c3b'
updates = []
for argument in sys.argv[1:]:
    target = Path(argument)
    original = target.read_bytes()
    digest = hashlib.sha256(original).hexdigest()
    if digest == after:
        continue
    if digest != before:
        raise SystemExit('Published readme differs from the reviewed patch; no commit is permitted.')
    changed = original.replace(b'Contributors: rubenwoudsma\n', b'Contributors: rubenw\n', 1)
    if hashlib.sha256(changed).hexdigest() != after:
        raise SystemExit('Contributor-only transformation did not match the approved content.')
    updates.append((target, changed))
for target, changed in updates:
    target.write_bytes(changed)
print('Both 2.0.0 readmes match the reviewed contributor-only content.')
PY

if [[ -z "$(svn status "$checkout")" ]]; then
  echo 'The published readmes already match.'
  exit 0
fi

svn diff "${targets[@]}"
if [[ "${DRY_RUN:-true}" == true ]]; then
  echo 'Preview finished; no publication performed.'
  exit 0
fi

: "${SVN_USERNAME:?Configure SVN_USERNAME}"
: "${SVN_PASSWORD:?Configure SVN_PASSWORD}"
svn commit "${targets[@]}" --non-interactive --no-auth-cache \
  --username "$SVN_USERNAME" --password "$SVN_PASSWORD" \
  --message "Correct Bulk Password Reset $release contributor username to rubenw"
