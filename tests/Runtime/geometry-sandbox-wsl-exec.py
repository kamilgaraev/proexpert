#!/usr/bin/env python3
import base64
import json
import os
import sys


payload = json.loads(base64.b64decode(sys.argv[1], validate=True))
arguments = payload["arguments"]
if (
    not isinstance(arguments, list)
    or not arguments
    or not all(isinstance(argument, str) for argument in arguments)
):
    raise SystemExit(125)
environment = os.environ.copy()
environment["PATH"] = payload["path"]
environment["GEOMETRY_LANDLOCK_SANDBOX_BINARY"] = payload["landlock_sandbox"]
os.execvpe(arguments[0], arguments, environment)
