# Scheduling timestamp recovery — 20 October 2026

The running local Reach poller now explicitly sends UTC `YYYY-MM-DDTHH:mm:ss.000Z`, retries a rejected format once in canonical form, and verifies the saved task before reporting success. Its pairing, schedule, credentials and selected model are unchanged. This immediate workaround is compatible with the current production server. The repaired prompt is also in the next hosted plugin, 0.4.12, prepared locally and not yet published/installed.

The server update normalizes ISO 8601 timestamps with explicit timezone: `Z` with or without fractional seconds, or a numeric offset such as `+03:00`. Tasks are stored at UTC second precision. It rejects invalid calendar values, missing timezones, relative phrases and times within five minutes, with an accepted-format example. The three hosted scheduling tool schemas now document date-time format, examples and optional milliseconds. `expected_due_at` remains the exact saved UTC SQL value for optimistic rescheduling checks.

Deploy the rebuilt `fieldwork-reach-upgrade.zip` with the existing backup-first installer in `UPGRADE.txt`. Pause the poller during deployment; resume after verifying the site. This patch requires no database migration beyond the existing repeatable schema procedure. The server patch has not been deployed by this chat. Contact feature, project permissions, conflict checks and email consent protections remain in place.

Before deployment, 27 pure timestamp checks and 22 isolated hosted scheduling integration checks passed, including all three scheduling tools, timezone conversion, invalid dates, stale retries and no partial saves. Existing agent-automation and project regressions passed. No test sends outreach.

Production recovery: three missing personal reminders were saved and re-read successfully using canonical `.000Z` input. A fourth callback had been superseded by a newer user conversation and was not recreated. The private recovery record is stored under var/, excluded from release packages. Earlier failure reports remain historical records.
