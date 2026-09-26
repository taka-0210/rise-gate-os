#!/usr/bin/env bash
set -Eeuo pipefail

echo "This in-place deployment entry point is retired by IR-1 Release Hardening." >&2
echo "Build an immutable RC artifact, complete G01-G12, and use deployment/deploy-release.sh." >&2
exit 78
