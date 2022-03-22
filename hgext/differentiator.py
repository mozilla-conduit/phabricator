# This software may be used and distributed according to the terms of the
# GNU General Public License version 2 or any later version.

import json
import os
import pathlib
import re
import subprocess

from mercurial import (
    error,
    registrar,
    templatekw,
)

testedwith = b"5.5.1"

keywords = {}
templatekeyword = registrar.templatekeyword(keywords)
phabricator_uri = os.getenvb(b"PHABRICATOR_URI")


def get_local_repo_callsign(repo) -> str:
    """Returns the callsign for the local repo parsed from the path.

    Uses the repository root path name as the repository ID and returns
    the corresponding repository callsign from conduit.
    """
    repo_path = pathlib.Path(repo.root.decode("utf-8"))
    repo_id = int(repo_path.name)

    # Search Conduit for all repositories.
    response = call_conduit("diffusion.repository.search", {})

    # Retrieve the repo objects.
    repo_objects = response["result"]["data"]

    for repo_object in repo_objects:
        # If the repo ID we parsed from the working directory matches the
        # ID of the current object, return the callsign for this object.
        if repo_id == repo_object["id"]:
            return repo_object["fields"]["callsign"]

    raise error.Abort(b"No repo found with ID %d" % repo_id)


def call_conduit(method: str, params: dict) -> dict:
    """Call the Conduit API using the local binary.

    Args:
        method: The method name to call (e.g. differential.revision.search).
        params: The parameters to pass in the API call.

    Returns:
        Parsed JSON response of the result.
    """
    params = json.dumps(params).encode("utf-8")
    command = [
        "/app/phabricator/bin/conduit",
        "call",
        "--method",
        method,
        "--input",
        "-",
    ]

    out = subprocess.run(command, input=params, capture_output=True).stdout
    result = json.loads(out)
    return result


def get_phab_server_callsign(differential_id: int) -> str:
    """Returns the repo "callsign" of the provided differential ID.

    Args:
        differential_id: The integer portion of the revision ID.

    Returns:
        A string representing the repo callsign fetched from Phabricator.
    """
    params = {"constraints": {"ids": [differential_id]}}
    result = call_conduit("differential.revision.search", params)
    repositoryPHID = result["result"]["data"][0]["fields"]["repositoryPHID"]
    params = {"constraints": {"phids": [repositoryPHID]}}
    result = call_conduit("diffusion.repository.search", params)
    callsign = result["result"]["data"][0]["fields"]["callsign"]
    return callsign.encode("utf-8")


def extsetup(ui):
    if not phabricator_uri:
        return

    differential_revision_re = re.compile(
        br"Differential Revision: %sD(?P<differential_id>\d+)" % phabricator_uri
    )

    # Remove the existing `desc` template keyword implementation.
    del templatekw.keywords[b"desc"]

    # Add our new `desc` keyword implementation
    @templatekeyword(b"desc", requires={b"repo"})
    def showdescription_without_differential(context, mapping):
        # Use the default `desc` implementation to get the description.
        desc = templatekw.showdescription(context, mapping)

        # Return if we don't get a match.
        match = differential_revision_re.search(desc)
        if not match:
            return desc

        differential_id = int(match.group("differential_id"))

        repo = context.resource(mapping, b"repo")

        # Grab callsign for current repo and current differential.
        local_callsign = get_local_repo_callsign(repo)
        phabricator_callsign = get_phab_server_callsign(differential_id)

        # If the callsign of the revision currently doesn't match the current
        # repo callsign, modify the message to reflect a non-existent revision.
        # NOTE: D500000 does not exist at the time of deploying this change. This
        # number was chosen arbitrarily.
        if local_callsign != phabricator_callsign:
            replacement = b"\n".join([
                b"Original commit seen on %s.\n" % phabricator_callsign,
                b"Differential Revision: %sD500000" % phabricator_uri,
            ])
            desc = differential_revision_re.sub(lambda _match: replacement, desc)

        return desc
