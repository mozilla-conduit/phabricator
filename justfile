# Development commands. Everything runs in Docker, so only `docker` and `just`
# are needed on the host. Run `just` to list the recipes.

# The `tools` service runs as the host user, so files it writes into the
# checkout stay owned by you.
export HOST_UID := `id -u`
export HOST_GID := `id -g`

# Worktrees point at the main repository's `.git` by absolute path, so the
# `tools` service mounts it at the same path.
export GIT_COMMON_DIR := `git rev-parse --path-format=absolute --git-common-dir`

tools := "docker compose run --rm tools"
tools_without_db := "docker compose run --rm --no-deps tools"

# List the available recipes.
default:
    @just --list

# Build the image the other recipes use. Rerun after changing the `Dockerfile`.
build:
    docker compose build tools

# Build the production image.
build-production:
    docker build --pull -t mozilla/phabricator --target production .

# Run all the tests, or only the tests under the given paths.
test *paths:
    {{tools}} test {{paths}}

# Lint changes since `origin/master`, or the given paths, or `--everything`.
lint *args:
    {{tools_without_db}} lint {{args}}

# Apply the linters' automatic fixes. Takes the same arguments as `lint`.
format *args:
    {{tools_without_db}} format {{args}}

# Rebuild the class maps. Run after adding, removing or renaming PHP classes.
liberate:
    {{tools_without_db}} liberate

# Rebuild the Celerity static resource map. Run after changing CSS or JS.
celerity:
    {{tools}} celerity

# Rebuild every generated map.
maps: liberate celerity

# Run the tests, and lint the changes since `origin/master`.
check: test lint

# Open a shell in the `tools` container.
shell:
    {{tools}} shell

# Start the local development environment.
up:
    docker compose up --build

# Stop the local development environment.
down:
    docker compose down
