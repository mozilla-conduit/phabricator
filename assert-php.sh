#!/bin/sh -ui
# Assert important PHP config invariants.
#
php-fpm -i | grep -q 'session.upload_progress.enabled => Off'
