# Company OS - IR-1 G5 Read-only Target Discovery STOP / Corrective-1

- Date: 2026-10-03 JST
- Gate: G5 Target Environment Build
- Frozen RC: 924af91188cc60d33ff87c91b94ecc1d539566e6
- Original operation: ONE G5 READ-ONLY TARGET DISCOVERY
- Original result: STOP before Production connection

## Original STOP evaluation

| Evidence | Result |
|---|---|
| displayed failure stage | PRODUCTION_READ_ONLY_INSPECTION |
| Production connection attempted | false |
| SSH / remote shell | not attempted |
| DB connection / SQL | not attempted / 0 |
| Production file or symlink mutation | 0 |
| Production DB mutation | 0 |
| local Production receipt count | 0 |
| retry performed | false |

The displayed Production stage was not an accurate layer classification. The
helper assigned that stage before preparing the remote script. The exception
occurred locally and the connection-attempt flag remained false.

## Root cause

The original line-ending normalization called String.Replace with a mixed
argument signature:

    old value: System.String
    new value: System.Char

PowerShell 5.1 could not select a String.Replace overload for that contract and
raised System.Management.Automation.MethodException. This happened while
preparing the script text and before the SSH native process invocation.

## Why previous verification did not catch it

- VerifyOnly returned before script selection and line-ending normalization.
- Package build parsed the shell scripts but did not execute the PowerShell
  helper's script-preparation path.
- The original focused regression inspected source contracts but did not assert
  that source preparation ran before the VerifyOnly exit.

## Corrective-1

- Added Read-LfScript with explicit String values for CRLF, CR, and LF.
- Moved exact script selection and normalization before the VerifyOnly exit.
- Added LOCAL_REMOTE_SCRIPT_PREPARATION as the pre-connection failure stage.
- Production stage is now assigned only immediately before setting the
  connection-attempt flag and invoking SSH.
- Added helper generation g5-discovery-corrective-1.
- Normalized unexpected local exceptions to UNEXPECTED_LOCAL_FAILURE instead
  of printing raw exception messages.
- Kept the G5 artifact and placement identity unchanged.
- Kept retry and overwrite prohibition unchanged.

## Production-free verification

| Verification | Result |
|---|---|
| PowerShell version | 5.1.26100.9444 |
| Original mixed Replace contract reproduction | STOP / MethodException |
| Correct String overload | PASS |
| PowerShell AST parse | PASS |
| Actual helper VerifyOnly through remote script preparation | PASS |
| Human execution path marker | validated_through_remote_script_preparation |
| G4 + G5 focused regression | 12 tests / 143 assertions PASS |
| Production connection | 0 |
| Production mutation | 0 |

## Identity preservation

- G5 Package SHA-256:
  f89a71cbfe453b2e9bd74d20fdf5e3bab9c2b98e28775ddf755922713ca8a232
- G5 Manifest SHA-256:
  b54040df69fb1c4658ff73f4920f482087d56e9d7fc223208c098fa2dbe5f1e1
- The helper is outside the immutable G5 Production artifact; no package file
  changed.

## Decision

**G5 DISCOVERY CORRECTIVE-1 READY / RE-EXECUTION WAITING**

The read-only discovery remains unexecuted. A separately authorized maximum
one corrective attempt is required. POSIX capability rehearsal, Production
Deploy, Migration, DNS, and SSL remain unauthorized.
