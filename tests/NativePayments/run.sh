#!/usr/bin/env bash
# Disposable Unix-socket database; never accepts an existing database or WHMCS config.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
MARIADB_BIN="${MARIADB_BIN:-/usr/bin}"
for executable in mariadb-install-db mariadbd mariadb mariadb-admin; do
  test -x "$MARIADB_BIN/$executable"
done
RUN_DIR="$(mktemp -d /tmp/pagou-payments.XXXXXXXX)"
DB_PID=""
cleanup() {
  result=$?
  if [ -n "$DB_PID" ]; then
    "$MARIADB_BIN/mariadb-admin" --no-defaults --socket="$RUN_DIR/mysql.sock" -uroot shutdown >/dev/null 2>&1 || kill "$DB_PID" 2>/dev/null || true
    wait "$DB_PID" 2>/dev/null || true
  fi
  if [ "$result" -eq 0 ]; then rm -rf -- "$RUN_DIR"; else echo "Synthetic test logs retained: $RUN_DIR" >&2; fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM
"$MARIADB_BIN/mariadb-install-db" --no-defaults --datadir="$RUN_DIR/data" --auth-root-authentication-method=normal --skip-test-db >"$RUN_DIR/init.log" 2>&1
"$MARIADB_BIN/mariadbd" --no-defaults --datadir="$RUN_DIR/data" --socket="$RUN_DIR/mysql.sock" --pid-file="$RUN_DIR/mysql.pid" --log-error="$RUN_DIR/mysql.log" --skip-networking --skip-log-bin --event-scheduler=OFF --innodb-buffer-pool-size=32M &
DB_PID=$!
ready=0
for ((i=0; i<100; i++)); do
  if "$MARIADB_BIN/mariadb-admin" --no-defaults --socket="$RUN_DIR/mysql.sock" -uroot ping >/dev/null 2>&1; then ready=1; break; fi
  kill -0 "$DB_PID"
  sleep 0.1
done
test "$ready" -eq 1
"$MARIADB_BIN/mariadb" --no-defaults --socket="$RUN_DIR/mysql.sock" -uroot -e 'CREATE DATABASE pagou_payments_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
export PAGOU_NATIVE_RUN_DIR="$RUN_DIR"
# Native library deprecations are excluded; warnings and errors still fail the suite.
"$PHP_BIN" -d error_reporting=24575 -d allow_url_fopen=0 -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,mail "$ROOT/tests/NativePayments/suite.php"
