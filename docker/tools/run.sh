#!/bin/sh
# Development tasks, run inside the `tools` Docker Compose service.
#
# The service bind-mounts the checkout twice: whole at `/src`, which is where
# `arc lint` and `arc liberate` run, and piecewise over the image's
# `/app/phabricator` and `/app/moz-extensions`, which is where the tests run.
# Use the `justfile` rather than calling this directly.

set -eu

ARC=/app/arcanist/bin/arc

usage() {
    echo "usage: $0 test|lint|format|liberate|celerity|shell [args...]" >&2
    exit 64
}

# Map a path relative to the checkout onto the image layout the tests run in.
container_path() {
    path="${1#./}"
    case "$path" in
        moz-extensions|moz-extensions/*) echo "/app/${path}" ;;
        *) echo "/app/phabricator/${path}" ;;
    esac
}

# Directories expand to every PHP file under a `__tests__` directory below
# them, and `arc unit` runs the test case classes among those. Other files go
# to `arc unit` unchanged, which runs the tests in their ancestor `__tests__`
# directories.
run_tests() {
    if [ "$#" -eq 0 ]; then
        set -- src moz-extensions/src
    fi

    targets=""
    for path in "$@"; do
        target=$(container_path "$path")
        if [ -d "$target" ]; then
            tests=$(find "$target" -path '*/__tests__/*' -name '*.php' | sort)
            targets="$targets $tests"
        elif [ -e "$target" ]; then
            targets="$targets $target"
        else
            echo "No such file or directory: $path" >&2
            exit 66
        fi
    done

    cd /app
    # The paths come from `find` within the checkout and contain no spaces.
    # shellcheck disable=SC2086
    exec "$ARC" unit --no-coverage $targets
}

command="${1:-}"
[ -n "$command" ] || usage
shift

# Without arguments `arc lint` asks which commit to diff against, so default to
# the changes since the branch left `origin/master`, including uncommitted ones.
case "$command" in
    lint|format)
        if [ "$#" -eq 0 ]; then
            set -- --rev origin/master
        fi
        ;;
esac

case "$command" in
    test)
        run_tests "$@"
        ;;
    lint)
        cd /src
        # `arc lint` exits 1 when the worst message is a warning, but also when
        # it crashes. Much of the upstream code predates this arcanist's rules,
        # so warnings are shown but not fatal: on 1, rerun silently at error
        # severity, which exits 0 unless `arc` itself failed. Other statuses
        # (2 for errors) are returned unchanged.
        status=0
        "$ARC" lint --never-apply-patches "$@" || status=$?
        if [ "$status" -eq 1 ]; then
            exec "$ARC" lint --never-apply-patches --severity error \
                --output none "$@" 2> /dev/null
        fi
        exit "$status"
        ;;
    format)
        cd /src
        exec "$ARC" lint --apply-patches "$@"
        ;;
    liberate)
        cd /src
        exec "$ARC" liberate -- src/
        ;;
    celerity)
        cd /app/phabricator
        exec ./bin/celerity map
        ;;
    shell)
        exec bash "$@"
        ;;
    *)
        usage
        ;;
esac
