#!/usr/bin/env bash
#
# Builds a clean, wordpress.org-ready plugin zip.
#
# Guarantees:
#   - The archive's top-level folder is ALWAYS "tukify" (the correct lowercase
#     slug), no matter what the working directory is named.
#   - Every entry in .distignore is excluded (dev tooling, tests, specs, hidden
#     files), plus OS junk like .DS_Store.
#
# Usage:  ./build.sh   ->   ./tukify.zip
#
set -euo pipefail

SLUG="tukify"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_ZIP="${SRC_DIR}/${SLUG}.zip"
STAGE="$(mktemp -d)"
DEST="${STAGE}/${SLUG}"

# Derive an rsync exclude file from .distignore (strip comments + blank lines;
# rsync does not understand '#' comments).
EXCLUDES="$(mktemp)"
grep -vE '^[[:space:]]*(#|$)' "${SRC_DIR}/.distignore" > "${EXCLUDES}" || true

mkdir -p "${DEST}"
rsync -a \
	--exclude-from="${EXCLUDES}" \
	--exclude='.DS_Store' \
	--exclude="/${SLUG}.zip" \
	"${SRC_DIR}/" "${DEST}/"

rm -f "${OUT_ZIP}"
( cd "${STAGE}" && zip -rqX "${OUT_ZIP}" "${SLUG}" )

rm -rf "${STAGE}" "${EXCLUDES}"
echo "Built ${OUT_ZIP}"
