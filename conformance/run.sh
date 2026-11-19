#!/usr/bin/env bash
#
# Runs the OpenID Foundation conformance suite ("Basic OP" and "Config OP"
# certification plans) against the package's demo provider, entirely in
# Docker, then removes every container and volume it created.
#
# Requirements: Docker with Compose v2, git, curl, at least 6 GB of free memory (override with MIN_FREE_GB),
# and `composer install` already run in the repository (vendor/ is copied
# into the provider container).
#
# Usage: ./conformance/run.sh
# Results: conformance/.runtime/results/ (exported plan zips and output.txt)

set -euo pipefail

SUITE_TAG="release-v5.3.4"
BASIC_PLAN="oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]"
CONFIG_PLAN="oidcc-config-certification-test-plan"
CONFIG_FILE="/config/laravel-openid-provider.json"
MIN_FREE_GB="${MIN_FREE_GB:-6}"

cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1 # stop Git Bash on Windows rewriting /config paths

free_gb() {
    if command -v powershell.exe >/dev/null 2>&1; then
        powershell.exe -NoProfile -Command "[int]((Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory/1MB)" | tr -d '\r'
    elif [ -r /proc/meminfo ] && grep -q MemAvailable /proc/meminfo; then
        awk '/MemAvailable/ { printf "%d", $2 / 1048576 }' /proc/meminfo
    elif command -v vm_stat >/dev/null 2>&1; then
        vm_stat | awk '/Pages free/ { gsub("\\.", "", $3); printf "%d", $3 * 4096 / 1073741824 }'
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

if [ ! -f ../vendor/autoload.php ]; then
    echo "Run composer install in the repository first." >&2
    exit 1
fi

mkdir -p .runtime/results
rm -rf .runtime/results/*

if [ ! -d .runtime/suite/scripts ]; then
    echo "Fetching conformance suite scripts (${SUITE_TAG})..."
    rm -rf .runtime/suite
    git clone --quiet --depth 1 --branch "${SUITE_TAG}" --filter=blob:none --sparse \
        https://gitlab.com/openid/conformance-suite.git .runtime/suite
    git -C .runtime/suite sparse-checkout set scripts
fi

cleanup() {
    echo "Stopping the conformance stack..."
    docker compose down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker compose pull --quiet mongodb server nginx op op-tls
docker compose up -d mongodb server nginx op op-tls

echo "Waiting for the conformance suite..."
for _ in $(seq 1 90); do
    if curl -ks -o /dev/null -w '%{http_code}' https://localhost:8443/ | grep -qE '^(200|302)$'; then
        break
    fi
    sleep 2
done

echo "Waiting for the demo provider..."
for _ in $(seq 1 90); do
    if docker compose exec -T op-tls wget -q --no-check-certificate -O /dev/null https://op.openid.test/.well-known/openid-configuration 2>/dev/null; then
        break
    fi
    sleep 2
done

docker compose exec -T op-tls wget -q --no-check-certificate -O - https://op.openid.test/.well-known/openid-configuration \
    > .runtime/results/discovery.json

set +e
docker compose run --rm runner sh -c "pip install --quiet --disable-pip-version-check --root-user-action=ignore httpx pyparsing && \
    python run-test-plan.py --export-dir /results --no-parallel --verbose \
    --expected-failures-file /expected/warnings.json --expected-skips-file /expected/skips.json \
    '${BASIC_PLAN}' ${CONFIG_FILE} \
    '${CONFIG_PLAN}' ${CONFIG_FILE}" 2>&1 | tee .runtime/results/output.txt
status=${PIPESTATUS[0]}
set -e

docker compose logs op > .runtime/results/op.log 2>&1 || true

echo
echo "Runner exit status: ${status}. Results are in conformance/.runtime/results/."
exit "${status}"
