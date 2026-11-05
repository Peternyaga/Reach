# Lead profile, cross-device refresh and optional close decisions — 13 October 2026

**Superseded for deployment by `EMAIL-AGENT-2026-10-13.md`.** The archive hash below belongs to the earlier package; the new combined archive also contains these features.

Prepared locally; not uploaded, deployed, pushed, or published.

## Upload and install

Upload `sales-service/publish/fieldwork-reach-upgrade.zip` to the DirectAdmin directory:

`/home/supashop/domains/reach.vumbualabs.com/`

Keep the filename `fieldwork-reach-upgrade.zip`. Do not upload it inside `public_html`.
In the DirectAdmin terminal, run:

```bash
cd /home/supashop/domains/reach.vumbualabs.com && unzip -oq fieldwork-reach-upgrade.zip -d fieldwork-upgrade && bash fieldwork-upgrade/install-upgrade.sh
```

The existing installer backs up the database, website and backend before updating;
it preserves `fieldwork/config.local.php` and runs the repeatable migration.
If it fails, retain its output and backup. File rollback is automatic after an
installation failure; database restoration is a deliberate separate operation.

After success, reload https://reach.vumbualabs.com/ once on each device so already-open
pages load the new code. Subsequent workspace changes refresh automatically.
Open a lead. Verify Contact
essentials and Ask the agent, collapsed context/follow-ups/history with previews,
project move, Initial lead progress and Call outcome. The manual opportunity and
follow-up creation forms should be gone. Check a phone viewport and the Close control.

For a lead you intend to close, **Close lead → Close lead and cancel follow-ups**
now works with **Decision (optional)** left blank. For an agent item needing human
review, **Acknowledge & close → Save decision & close** also accepts a blank
**Your review decision (optional)**. Supplied text stays in history; blank text
does not invent a user decision. Closing a lead still cancels its pending tasks,
while acknowledging an agent review keeps its report and follow-up tasks.
Correction requests and reversal requests still need instructions/reasons.

## Cross-device verification

Keep the workspace open in two signed-in sessions. Complete an existing authorized
task in one session and check the other session's Today/Schedule and open lead
profile. Visible sessions check for changes every three seconds, so normal updates
should arrive within about three seconds plus request time. The local browser test
measured about 1.8 seconds from desktop completion to mobile verification.

The check uses an authenticated, user/workspace-scoped fingerprint and returns HTTP
304 with no body when unchanged. Updated lists retain the selected project; open
lead details, agent reports, team and connection views also refresh. It uses the
existing PHP/MySQL service without an extra process to configure.

Try keeping a harmless unsaved draft open while changing a record in the other
session. The draft should stay intact; updates wait until the form is finished,
restored or cancelled. Existing version conflicts still prevent stale writes.
Account/role changes or session expiry clear views the account can no longer use.

Hidden/offline sessions pause polling. Focus, visibility, page resume and network
reconnection trigger an immediate check; failed requests retry with backoff up to
30 seconds. The feature provides synchronization, not offline editing or guaranteed
instant push delivery. Physical phone sleep/network recovery and production latency
have not been tested for this release.

Start a fresh Codex chat with the existing Fieldwork connection and verify discovery
of `create_agent_opportunity`. It uses the existing `leads:write` scope, a pending
authenticated user request and current lead version. Internal follow-ups use the
existing `schedule_agent_followup` tool and `tasks:write` scope. Read-only grants
cannot create opportunities; missing permissions still require normal OAuth consent.
Only queue a real opportunity or follow-up when you want that actual record created.

The updated agent-work skill remains in local plugin source at
`sales-workspace-hosted/skills/agent-work/SKILL.md`. It has not been published to the
marketplace or refreshed into the installed cache; the website archive does not
install plugins. Existing generic user-request guidance and the new server tool
description support discovery/use after the server upgrade. No production end-to-end
agent run has been performed for this release.

## Package verification

- Size: 145,230 bytes; 34 archive entries.
- SHA-256: `8773E6ABF1DA25CD42B616911F29CC795F7AAD8BA2994972A69B0DE8D1992B44`.
- Required public files, matching changed backend files/built frontend and LF installer verified.
- No local configuration, credentials, database state, node_modules or tests included.

## Local validation

Production build/package, PHP syntax, `tests/agent-automation.php`,
`tests/lead-profile.php`, `tests/integration.php` and loopback `tests/hosted-oauth.php`
passed. Coverage includes explicit queued opportunity/follow-up creation, retries,
role/workspace boundaries, stale/closed lead rejection, OAuth scope enforcement,
durable reports and manual review for opportunity/multi-action reversals.

The updated integration and agent-automation suites also passed empty,
whitespace-only, omitted and supplied close decisions, durable audit/report
retention, cancellation semantics, stale versions and idempotent review retries.
Browser fixtures using the actual components verified labelled optional fields,
enabled blank-submit buttons, Enter submission and retained supplied decision text.
These optional-text changes need no database constraint or schema changes. They
have not been deployed or verified on production or an actual screen reader.

`php tests/workspace-sync.php` passed 34 checks for conditional responses, independent
sessions, role/workspace privacy, lead/task/project/activity/opportunity/agent/team/
connection changes, project-scoped lists, uncommitted changes and deletions.
`node --test tests/workspace-sync.mjs` passed eight checks for request coalescing,
draft protection, hidden/offline/resume behavior, retry backoff, expiry and teardown.

Two independent local browser sessions (Chrome owner and in-app salesperson at
390×844) verified task completion, preserved open/expanded profile state, protected
agent/stage drafts, automatic refresh when a draft was restored, project creation
and moves without changing the mobile selection. Corrected duplicate profile
component keys; fresh sessions had no console errors during a remote stage update.
Disposable browser accounts, records and credentials were removed afterward.

The real React app was reviewed through a read-only synthetic fixture at 1280×720
and 390×844. Mobile expanded content had equal client/scroll width (357px), native
Enter disclosure controls worked, Tab wrapped to Close, Escape restored focus,
and the Close button stayed above mobile navigation at approximately y=21px.
Actual device and screen-reader testing remain unverified.
