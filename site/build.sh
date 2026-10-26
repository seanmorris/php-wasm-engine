#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"

mkdir -p .cache
lock_dir=.cache/site-build.lock

while ! mkdir "${lock_dir}" 2>/dev/null; do
	owner=""
	[ ! -r "${lock_dir}/pid" ] || read -r owner < "${lock_dir}/pid"

	if [[ ${owner} =~ ^[0-9]+$ ]] && ! kill -0 "${owner}" 2>/dev/null; then
		rm -rf -- "${lock_dir}"
		continue
	fi

	sleep .1
done

printf '%s\n' "$$" > "${lock_dir}/pid"
pages_dir=$(mktemp -d .cache/pages-build.XXXXXX)

cleanup()
{
	rm -rf -- "${lock_dir}"
	[ -z "${pages_dir}" ] || rm -rf -- "${pages_dir}"
}

trap cleanup EXIT

PAGES_DIR="${pages_dir}" node tools/prepare.mjs
PAGES_DIR="${pages_dir}" ./tools/smgen build "$@"
node tools/publish.mjs

rm -rf -- .cache/pages
mv -- "${pages_dir}" .cache/pages
pages_dir=""
