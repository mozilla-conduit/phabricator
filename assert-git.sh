#!/bin/sh
# Fail the build rather than shipping an image where the merge-conflict engine
# (RevisionMergeConflictEngine) would silently fall back to the rename-blind
# legacy path: it needs `git merge-tree --write-tree --merge-base`
# (git >= 2.40), which is also the only path that follows renames.
#
# Run at the end of every final stage, not just in `base`: each stage installs
# further packages afterwards, and a change of base image or Alpine release can
# change the git they end up with.
set -eu

git --version
git merge-tree -h 2>&1 | grep -q 'write-tree'
git merge-tree -h 2>&1 | grep -q 'merge-base'

# Git's HTTPS transport is how Phabricator fetches observed repositories, and it
# is the part most easily broken by a downgraded libcurl or openssl. Checking
# the version output alone would not notice.
git ls-remote https://github.com/mozilla-conduit/phabricator HEAD > /dev/null
