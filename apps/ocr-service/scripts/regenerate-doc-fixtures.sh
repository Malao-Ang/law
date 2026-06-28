#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OCR_SERVICE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FIXTURES_DIR="${OCR_SERVICE_DIR}/tests/fixtures"
TARGET_PATH="${FIXTURES_DIR}/sample.doc"

mkdir -p "${FIXTURES_DIR}"

TARGET_PATH="${TARGET_PATH}" perl -e 'use strict; use warnings; my $path = $ENV{"TARGET_PATH"}; open my $fh, ">:raw", $path or die "open $path: $!"; print {$fh} pack("H*", "D0CF11E0A1B11AE1"), "legacy-doc-fixture\n"; close $fh or die "close $path: $!";'

printf 'Wrote %s\n' "${TARGET_PATH}"
