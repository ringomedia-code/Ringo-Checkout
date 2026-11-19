#!/bin/sh
# Run every scenario of tests/secrets-harness.php in its own PHP process.
# PHP_CMD defaults to "php"; override for other builds, e.g.
#   PHP_CMD="npx --offline @php-wasm/cli@3.1.58" tests/run-secrets-harness.sh
# (not $PHP: php-wasm reads that variable as its PHP version)
PHP_CMD="${PHP_CMD:-php}"
cd "$(dirname "$0")/.." || exit 2
list=$($PHP_CMD tests/secrets-harness.php --list) || { echo "could not list scenarios"; exit 2; }
ran=0
fail=0
for s in $list; do
	ran=$((ran + 1))
	out=$($PHP_CMD tests/secrets-harness.php "$s" 2>&1)
	echo "$out"
	case "$out" in *"ok   [$s]"*) ;; *) fail=$((fail + 1)) ;; esac
done
if [ "$ran" -eq 0 ]; then echo "no scenarios ran"; exit 2; fi
if [ "$fail" -ne 0 ]; then echo "FAILED: $fail of $ran scenario(s)"; exit 1; fi
echo "ALL $ran SCENARIOS PASSED"
