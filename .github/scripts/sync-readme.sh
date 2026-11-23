#!/usr/bin/env bash
# Inspired by Joost de Valk's readme-sync workflow; only existing readmes change.
set -euo pipefail

read_tag() {
  awk 'tolower($0) ~ /^stable tag:[[:space:]]*/ { sub(/^[^:]*:[[:space:]]*/, ""); sub(/[[:space:]]*$/, ""); print }' "$1"
}

release=$(read_tag readme.txt)
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

for target in "${targets[@]}"; do
  cp -- readme.txt "$target"
done

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
  --message "Update Bulk Password Reset $release readme"
