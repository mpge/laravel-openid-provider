#!/usr/bin/env bash
#
# Moodle smoke test: Moodle 4.5 (auth_oauth2, configured from discovery as a
# confidential client without PKCE) signs in through the demo provider twice.
# Passes when the first sign-in creates exactly one Moodle account keyed on
# the OpenID subject and the second sign-in reuses it.
#
# Requirements: Docker with Compose v2, at least 6 GB of free memory
# (override with MIN_FREE_GB), and `composer install` run in the repository.
#
# Usage: ./moodle-smoke/run.sh
# Output: moodle-smoke/.runtime/result.txt

set -euo pipefail

MIN_FREE_GB="${MIN_FREE_GB:-6}"

cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1

free_gb() {
    if command -v powershell.exe >/dev/null 2>&1; then
        powershell.exe -NoProfile -Command "[int]((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory/1MB)" | tr -d '\r'
    elif [ -r /proc/meminfo ] && grep -q MemAvailable /proc/meminfo; then
        awk '/MemAvailable/ { printf "%d", $2 / 1048576 }' /proc/meminfo
    else
        echo 99
    fi
}

available="$(free_gb)"
available="${available:-0}"
if [ "${available}" -lt "${MIN_FREE_GB}" ]; then
    echo "Only ${available} GB of memory free; at least ${MIN_FREE_GB} GB is needed. Aborting." >&2
    exit 1
fi

mkdir -p .runtime
: > .runtime/result.txt

cleanup() {
    echo "Stopping the Moodle stack..."
    docker compose logs op > .runtime/op.log 2>&1 || true
    docker compose logs moodle > .runtime/moodle.log 2>&1 || true
    docker compose down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

log() { echo "$*" | tee -a .runtime/result.txt; }

sql() {
    docker compose exec -T db psql -U moodle -d moodle -tA -c "$1" | tr -d '\r'
}

docker compose up -d db moodle-code moodle op op-tls

echo "Waiting for the provider and Moodle..."
for _ in $(seq 1 120); do
    if docker compose exec -T moodle curl -sfk -o /dev/null https://op.openid.test/.well-known/openid-configuration 2>/dev/null; then
        break
    fi
    sleep 3
done
docker compose exec -T moodle curl -sfk https://op.openid.test/.well-known/openid-configuration > .runtime/discovery.json

# Moodle's cURL reads its CA bundle from moodledata/moodleorgca.crt when present.
# Add the TLS proxy's private root to the system bundle there.
docker compose exec -T moodle sh -c 'cat /etc/ssl/certs/ca-certificates.crt /caddy/caddy/pki/authorities/local/root.crt > /var/www/moodledata/moodleorgca.crt && chown www-data /var/www/moodledata/moodleorgca.crt'

echo "Installing Moodle..."
docker compose exec -T -u www-data moodle php admin/cli/install_database.php \
    --agree-license --adminuser=admin --adminpass='Smoke-Admin-1!' \
    --adminemail=admin@example.com --fullname='Smoke test' --shortname=smoke > .runtime/install.log

log "== OAuth 2 service (created from discovery) =="
docker compose exec -T -u www-data moodle php /smoke/setup-oauth2.php | tr -d '\r' | tee -a .runtime/result.txt

run_client() {
    docker compose run --rm client sh -c "pip install --quiet --disable-pip-version-check --root-user-action=ignore requests && python /smoke/smoke.py"
}

log "== First sign-in =="
run_client | tee -a .runtime/result.txt
first_users="$(sql "SELECT count(*) FROM mdl_user WHERE auth = 'oauth2' AND deleted = 0")"
first_user="$(sql "SELECT id || ' ' || username || ' ' || email || ' ' || firstname || ' ' || lastname FROM mdl_user WHERE auth = 'oauth2' AND deleted = 0")"
log "oauth2 accounts after first sign-in: ${first_users} (${first_user})"

log "== Second sign-in (fresh browser) =="
run_client | tee -a .runtime/result.txt
second_users="$(sql "SELECT count(*) FROM mdl_user WHERE auth = 'oauth2' AND deleted = 0")"
second_user="$(sql "SELECT id || ' ' || username || ' ' || email || ' ' || firstname || ' ' || lastname FROM mdl_user WHERE auth = 'oauth2' AND deleted = 0")"
linked="$(sql "SELECT count(*) FROM mdl_auth_oauth2_linked_login")"
log "oauth2 accounts after second sign-in: ${second_users} (${second_user})"
log "linked logins: ${linked}"

if [ "${first_users}" = "1" ] && [ "${second_users}" = "1" ] && [ "${first_user}" = "${second_user}" ]; then
    log "RESULT: PASS (one account created, reused on the second sign-in)"
else
    log "RESULT: FAIL"
    exit 1
fi
