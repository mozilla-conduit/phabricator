<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * An explanation, written by the merge check itself, of why it cannot answer.
 *
 * The message is rendered on the revision and handed to Lando, so it is written
 * for that audience. Anything else that escapes the check is logged and
 * replaced with a fixed string instead, since an arbitrary exception message
 * can carry server paths and other details that have no business being shown to
 * a reader of the revision.
 */
final class RevisionMergeConflictReasonException extends Exception {}
