#!/bin/sh
# Fail the build rather than shipping an image where the merge-conflict engine
# (RevisionMergeConflictEngine) would silently fall back to the rename-blind
# legacy path, or where git cannot run at all against the musl present in the
# image.
#
# Run at the end of every final stage, not just in `base`: each stage installs
# further packages afterwards, and those come from Alpine 3.13 while git comes
# from 3.21. A later `apk add` can pull a dependency back to 3.13 and leave git
# broken.
set -eu

git --version
git merge-tree -h 2>&1 | grep -q 'write-tree'
git merge-tree -h 2>&1 | grep -q 'merge-base'

# Git's HTTPS transport is how Phabricator fetches observed repositories, and it
# is the part most easily broken by a downgraded libcurl or openssl. Checking
# the version output alone would not notice.
git ls-remote https://github.com/mozilla-conduit/phabricator HEAD > /dev/null
