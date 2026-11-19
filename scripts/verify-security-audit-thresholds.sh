#!/usr/bin/env bash
set -euo pipefail

mode="${1:?Usage: $0 composer|npm|all}"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

verify_composer_threshold() {
    local fixture_dir="$tmp_dir/composer"
    local report="$tmp_dir/composer-audit.json"

    mkdir -p "$fixture_dir"

    cat > "$fixture_dir/composer.json" <<'JSON'
{
    "name": "cr8or/security-audit-threshold-fixture",
    "type": "project",
    "require": {
        "symfony/http-foundation": "8.1.7"
    }
}
JSON

    cat > "$fixture_dir/composer.lock" <<'JSON'
{
    "_readme": [],
    "content-hash": "security-audit-threshold-fixture",
    "packages": [
        {
            "name": "symfony/http-foundation",
            "version": "v8.1.7",
            "type": "library"
        }
    ],
    "packages-dev": [],
    "aliases": [],
    "minimum-stability": "stable",
    "stability-flags": [],
    "prefer-stable": false,
    "prefer-lowest": false,
    "platform": [],
    "platform-dev": [],
    "plugin-api-version": "2.9.0"
}
JSON

    # This deliberately vulnerable version must be rejected by Composer's audit.
    if composer --working-dir="$fixture_dir" audit --locked --format=json --no-interaction > "$report"; then
        echo 'Composer audit unexpectedly accepted the vulnerable fixture.' >&2
        return 1
    fi

    php -r '
        $report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $advisories = $report["advisories"] ?? [];
        if (array_sum(array_map("count", $advisories)) < 1) {
            fwrite(STDERR, "Composer audit failed without reporting a vulnerability.\n");
            exit(1);
        }
    ' "$report"

    echo 'Composer audit correctly rejected the vulnerable fixture.'
}

verify_npm_threshold() {
    local fixture_dir="$tmp_dir/npm"
    local report="$tmp_dir/npm-audit.json"

    mkdir -p "$fixture_dir"

    cat > "$fixture_dir/package.json" <<'JSON'
{
    "name": "cr8or-security-audit-threshold-fixture",
    "version": "1.0.0",
    "private": true,
    "dependencies": {
        "lodash": "4.17.20"
    }
}
JSON

    npm install --prefix "$fixture_dir" --package-lock-only --ignore-scripts --no-audit --no-fund --silent

    # This deliberately vulnerable version has a high-severity advisory.
    if npm audit --prefix "$fixture_dir" --audit-level=high --json > "$report"; then
        echo 'npm audit unexpectedly accepted the vulnerable fixture.' >&2
        return 1
    fi

    node -e '
        const report = JSON.parse(require("node:fs").readFileSync(process.argv[1], "utf8"));
        const vulnerabilities = report.metadata?.vulnerabilities ?? {};
        if ((vulnerabilities.high ?? 0) + (vulnerabilities.critical ?? 0) < 1) {
            process.stderr.write("npm audit failed without reporting a high- or critical-severity vulnerability.\n");
            process.exit(1);
        }
    ' "$report"

    echo 'npm audit correctly rejected the vulnerable fixture at the high threshold.'
}

case "$mode" in
    composer)
        verify_composer_threshold
        ;;
    npm)
        verify_npm_threshold
        ;;
    all)
        verify_composer_threshold
        verify_npm_threshold
        ;;
    *)
        echo "Unknown audit type: $mode" >&2
        echo "Usage: $0 composer|npm|all" >&2
        exit 2
        ;;
esac
