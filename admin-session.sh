#!/usr/bin/env bash
# Reuse the exported admin session from any terminal.
#   ./admin-session.sh                 -> print dashboard HTML
#   ./admin-session.sh /books.php      -> print any admin page
#   ./admin-session.sh -e "<js>"      -> not supported, use curl directly
#
# Session file on disk: /var/lib/php/sessions/sess_b922935c1d7942c87a11d721a99105e7
set -euo pipefail
COOKIE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/admin-session.cookie"
BASE="http://127.0.0.1:8082"
curl -s -b "$COOKIE" "${BASE}${1:-/index.php}"
