# Project organization — prepared 14 October 2026

This is a combined local upgrade. Peter deploys it himself. No production upload, marketplace publication, installed-plugin refresh, real SMTP credentials or real email were used for this task.

## Boundary and shared identity

One workspace login/team remains. Owner/manager access spans its projects; salespeople and collectors retain their existing assignment/submission visibility. Projects do not yet have a separate membership editor. Within those permissions every record request has an explicit intended project: session web requests carry X-Fieldwork-Project (query project may override it), hosted/local MCP record tools require project_id, and missing web context defaults only to General. Browser selection is never saved in PHP session or consulted by an agent. Explicit All projects is a read view, never a mutation context.

| Area | Enforced relationship |
| --- | --- |
| Leads and profile/stage/assignment/closure | Workspace + intended project + role/record access. Mutations lock the lead before checking. |
| Contacts/organisations | Explicit project columns, workspace/project foreign keys; migration splits cross-project organisation links without deleting source rows. All current access goes through a permitted lead. No contact/organisation catalogue or merge endpoint exists. |
| Opportunities, tasks/schedule, activities, agent work/reports/reviews | Derive the current project by their linked lead/contact, with server-side scope on lists and record actions. IDs from another intended project return 404 even for managers. |
| Intake and CSV | Explicit project; header/body disagreement rejected. Duplicate checks stay inside that project. Request payload hashes retain project identity. |
| Dashboard and Pipeline totals | Dedicated scoped SQL aggregates, independent of capped list responses. Collectors get no private task/opportunity totals. |
| Email connection | Multiple encrypted accounts private to a user. One sender selection per workspace/project/user, constrained to that same user's account. There is no fallback mailbox. |
| Email authorization/history | Composer carries recipient/version and sender ID/revision. Agent task captures requester, project, recipient and sender ID/revision, in addition to explicit permission and OAuth email:send. Changed sender/configuration/recipient or moved work cannot reuse consent. New outbound rows retain sender and origin project; visibility follows the current lead. |
| Sync/poller/MCP | Sync fingerprints scope lead-derived data to the selected project. Project inventory, own connections and shared team remain global metadata. Poll readiness respects current role. Poller explicitly discovers all accessible work, then uses each item's own project_id for every action. Availability conflicts span projects for the same teammate and disclose no conflicting record identity. |
| Lead moves | Atomic, current-version checked, destination duplicates checked. Contact/organisation links follow safely; tasks, opportunities, history/email and reports stay attached. Pending agent work becomes needs_review with a move explanation. In-flight email claims block moving. |

## Migration decisions

`backend/migrate.php` can be rerun. Existing leads retain their project and null legacy leads receive General as before. Contacts inherit the source lead project. Legacy organisation links crossing projects are split atomically with exact source lineage retained; historical/unlinked organisation rows are retained and unassigned legacy organisations receive General. Credentials from legacy mailboxes are copied byte-for-byte into deterministic mail_accounts IDs using a per-mailbox durable migration marker. Re-running cannot overwrite edits or resurrect disconnected accounts. Legacy mailbox rows and the persistent mail.key are retained. No sender mapping is inferred, so the user must choose the intended project sender after upgrade.

Existing outbound history stays intact. Its project is backfilled from the linked lead where absent; this cannot reconstruct a historical project before earlier moves. Legacy sender addresses remain unknown rather than being inferred from today's configuration. Earlier agent email consent lacks the new project/sender snapshot and must be renewed in a new explicit request. Queued internal work can continue with the refreshed plugin.

SMTP sends commit a durable sending claim before external I/O. Success becomes sent; failure/uncertainty becomes needs_check. An interrupted process may leave sending, and retries return that claim without another SMTP call. A lead with such a claim cannot move until delivery is investigated. There is no automatic delivery reconciliation UI; if a crash leaves sending, an administrator must inspect provider evidence and deliberately reconcile that row before moving. A sender revision is accepted at send initiation; editing/disconnecting an account cannot recall a message already in flight.

## Verification and limits

`tests/projects.php` exercises project/role boundaries, selected/All views, counts, wrong-project detail/profile/assignment/task/review/reversal actions, explicit MCP schemas/context, private SMTP accounts and sender revisions, fake transport routing, durable uncertain delivery, moves and pending-work review, and repeated legacy migration including cross-project organisations. Existing integration, agent, profile, mail, sync and isolated OAuth/MCP suites are rerun; exact results and archive hashes are recorded in PROGRESS.md.

The actual React app is reviewed using a disposable local workspace with two projects, two fake SMTP configurations and reports. Desktop/mobile switching, selected/all reports, opening a cross-project report, displayed sender consent, per-project SMTP choices, counts, responsive fit and dialog Close/Escape are checked. No real transport is invoked. Production migration, live provider delivery, refreshed production poller behavior, physical devices, screen readers and large-volume migration performance are not claimed. Lists still have existing limits (web records 500, Agent activity 100, MCP lead/task 100 and work 50); dashboard aggregates are exact. This work does not add inbound mail sync, campaigns, project memberships, contact merging or external scheduling.
