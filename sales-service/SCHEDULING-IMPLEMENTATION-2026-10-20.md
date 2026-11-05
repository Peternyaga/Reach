# Scheduling recovery and prevention — 20 October 2026

Peter requested a fix for timestamp failures and recovery of the missing reminders. After learning that Funcy's exact callback time had passed, Peter supplied a newer conversation and said a fresh agent task had been placed. That newer completed task supersedes the old callback; no replacement time was invented.

Completed production actions: three missing personal reminders created through authenticated Reach tools with canonical UTC timestamps, then independently re-read. Each lead has exactly one matching pending task, the correct due time, and Peter as task owner. No salesperson assignment changed. Private IDs and source-request references are in ignored `var/scheduling-recovery-2026-10-20.json`, excluded from archives. Prior needs_review reports remain truthful history.

Immediate prevention: updated the existing ignored `.fieldwork-reach/poller.ps1` prompt to require `.000Z`, retry a timestamp validation rejection once with the same intended instant, reconcile uncertain responses against actual saved tasks, and verify task ID/time/owner before completion. Added guidance for explicit requester-owned personal tasks on unassigned leads, and checking newer notes before recovery. No pairing, credentials, scheduled task, model or reasoning configuration was changed; no poller run was triggered. Both modified PowerShell scripts parse successfully.

Server source: new `backend/followup-time.php` normalizes timezone-qualified ISO 8601 (optional fractional seconds and explicit offsets) to UTC second precision. It rejects invalid calendar values, absent timezone and deadlines within five minutes with examples. All three hosted scheduling schemas expose date-time format and examples. Exact optimistic `expected_due_at` SQL values remain unchanged. No schema changes or permission changes are required.

Validation: 27 timestamp checks and 22 isolated hosted scheduling checks passed. Existing agent-automation and project-isolation (91) regressions passed without errors. Production build, edited skill validation, syntax checks and archive CRC/inventory/exclusion/source parity all passed. Tests create only disposable local records and send no outreach.

Release status: server fix deployed successfully on 20 October 2026 following Peter's explicit "Deploy" instruction. Hosted plugin 0.4.12 prepared, not published or installed; the existing scheduled worker has already received the immediate workaround directly. Published/installed hosted plugin 0.4.11 remains in place. Local 0.1.2 plugin content is unchanged; its regenerated ZIP only carries the current release guide.

Artifacts and SHA-256:
- `publish/fieldwork-reach-upgrade.zip`: `01664cbe57b5038a000e6312592d2eda64be1a84ce61759aa2ce94a3ba329253` (42 files).
- `publish/fieldwork-reach-plugin-0.4.12.zip`: `e3b17bb2cc05b5231e144ce8afa3cb4c724e5a4754aef0f406874c5ead84a381`.
- `publish/fieldwork-local-plugin-0.1.2.zip`: `3e31152cf844433dbf9c8bab90188d2ca03c6b41a2944bb3f20761c1eaa3c238`.
- Full inventory: `publish/RELEASE-CHECKSUMS-2026-10-20.json`. The previously deployed contact ZIP was preserved as `publish/fieldwork-reach-contacts-2026-10-19.zip` before rebuilding.

Production deployment: uploaded the checksum-verified archive outside public_html and ran the backup-first installer successfully. Backup: `/home/supashop/domains/reach.vumbualabs.com/fieldwork-backups/20261020T093500Z`. Maintenance mode cleared; deployed parser/router/write files match the release. Live PHP timestamp normalization passed; public site returned HTTP 200; all three scheduling tool schemas expose date-time and optional milliseconds. Authenticated Reach access and existing reminders verified. A no-op reschedule of an existing reminder using a numeric timezone offset without milliseconds returned ok/replayed with its original due time. The local worker mutex was held during installation and released successfully afterward.

Peter's later reschedule of Hushfrah to 21 October 2026 at 11:00 Africa/Nairobi was observed and preserved. Homey remains 21 October at 09:00 and Hardi at 10:00; Funcy remains awaiting an inbound callback under the newer completed agent task. No duplicate reminder was created during verification.

Deployment evidence: `var/deployment/scheduling-2026-10-20.png`. Server deployment and reminder recovery are complete. A separate marketplace publication can distribute prepared plugin 0.4.12 guidance to other installations; it was not included in this server deployment.
