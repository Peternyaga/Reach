# Fieldwork — local sales workspace

React + PHP 8.2+ + MySQL/MariaDB. The hosted application runs at https://reach.vumbualabs.com; see deploy/README.md for hosting and PROGRESS.md for historical checkpoints.

## Run locally

1. Start your local MySQL service. Create a dedicated `sales_workspace` database. Copy `config.example.php` to `config.local.php` to override database credentials. The example uses local XAMPP defaults; use a restricted database user before hosting.
2. Run `php backend/migrate.php` to create or update the schema. On an empty local database, the website offers first-run owner setup so the owner chooses their own password. To use local email, create a private 32-byte key once in ignored `var/mail.key` (64 hexadecimal characters); protect and back it up with the database. The hosted upgrade installer creates this key automatically. The older `php backend/provision-local.php` remains available only for the legacy local plugin prototype; it creates an owner and saves generated credentials to ignored `var/local-account.json`.
3. Run `php -S 127.0.0.1:8788 backend/router.php` from this directory.
4. Run `npm install`, then `npm run dev`; open http://127.0.0.1:5178. Vite proxies API requests to PHP. Complete first-run owner setup, or sign in if the workspace already exists. Existing legacy local accounts retain their ignored local account file.

PHP's development server is loopback-only. Do not expose it publicly. The hosted release uses the routing and configuration in deploy/. The prepared upgrade adds optional per-user SMTP email; it is not live until installed. See deploy/EMAIL-AGENT-2026-10-13.md for setup and limitations.

## Current workflow

Select a project in the sidebar before working. Leads, tasks, opportunities and import previews are scoped to the selected project; owners and managers can create projects and move leads between them. General is the default project. Projects are enforced request boundaries for records and workflows. Each request carries its intended project; record IDs from another project are rejected. Login and team roles remain shared, with owners/managers able to choose any workspace project and salespeople/collectors retaining their existing record visibility. Agent activity defaults to the selected project and offers an explicit All projects view. See PROJECT-ORGANIZATION.md.

The Leads list and the dashboard's **Next relationships** section put the earliest pending follow-up first, including overdue work. Completed tasks do not affect this order, and leads without a pending follow-up appear last.

From Today, Schedule, or a lead profile, cancel a pending follow-up with a reason when it will not happen. It remains in the record as Cancelled rather than Done. To stop pursuing a lead, open its profile and choose **Close lead**; enter the reason. This marks the lead Lost and cancels all its remaining pending tasks while preserving completed work and activity history. Change its stage again to reopen it before adding new follow-ups.

Capture a lead with source, collector, offering and suggested owner; review matching contact warnings in the capture form; preview and import CSV batches through Intake; assign unowned leads to active team members with a first-contact due date; edit sales stage with stale-write detection; record notes; create internal follow-ups; complete them from Today or Schedule. A lead profile now shows separate sales opportunities: add another product or service, move its stage independently, and see each opportunity in Pipeline. When capture matches an accessible existing contact, the form offers a link to its profile so another opportunity can be added there. Assignment and the first-contact task are saved together, so a failed retry cannot duplicate the task. Data persists in MySQL. No invented sample leads are inserted. CSV imports accept up to 1000 rows or 900 KB with a `name` header and optional organisation, email, phone, source, offering, owner, collector and notes columns. The CSV owner field is a suggestion only; assignment is a separate reviewed action.

The initial lead row remains an intake record. Separate organisation, contact and opportunity tables now store the first offering and any additional opportunities for that contact. Owners and managers can see the workspace and assign leads; salespeople see leads and opportunities assigned to or submitted by them; collectors see only their own handoff records and cannot see sales notes, opportunities or tasks. Assignment changes retain a separate event history.

The owner can open **Team & access** to invite a manager, salesperson or collector. The app shows a one-use setup link valid for 48 hours; the recipient opens it and sets a password before the account becomes active. For an active member who loses access, the owner can issue a one-use reset link valid for 30 minutes. Links are displayed to the owner for private sharing and are not sent automatically. Redeeming a reset invalidates existing sessions for that account. If the only owner loses access, run `php backend/recover-local-owner.php owner@example.com` on the local machine to print a one-use recovery link. The older `backend/add-user.php` CLI remains for local testing.

Contact matching and merge review, shared organisations across multiple people, campaigns and configurable playbooks remain on the implementation queue. No email, SMS or WhatsApp is sent, and follow-ups do not yet ring alarms.

## Codex connection

`../sales-workspace` is the source plugin. Its setup skill guides Codex to open the local web app for account creation after the plugin is installed and setup is invoked. Plugin installation itself does not auto-open a browser. After signing in, choose **Connect Codex** in Fieldwork. The service saves a local, revocable 30-day connection token in ignored `var/plugin-access.json`; the plugin reads that file without receiving the user's password. Reconnecting rotates the token, and resetting the account password invalidates it. The stdio MCP bridge uses the token to get a session/CSRF pair and provides lead lookup, capture, assignment, CSV preview/import, notes and task tools. The bridge still accepts the legacy local account file if no connection token exists. The plugin is source-only and is not installed automatically. This single-machine local connection is not hosted authentication; account-specific installation and token storage must be hardened before broad distribution.

The MCP bridge also lists and creates projects, scopes lead/task searches and intake to a project, and lets an owner or manager move a lead using its current version. Its project capability is available through the same account connection as the other tools.

When the plugin package is copied outside this repository (as Codex does during installation), set `FIELDWORK_SERVICE_ROOT` to the absolute `sales-service` directory in the MCP server's environment. The bridge needs that path to read the local connection file. A copy in a separate folder passed the protocol lookup with this setting; marketplace installation in the Codex app has not yet been verified.

## Checks

`npm run build` builds the frontend. `php tests/integration.php` checks a temporary isolated test workspace against MySQL and cleans up only records created by that test. `node tests/plugin-protocol.mjs` checks the local MCP bridge. PHP files can be syntax checked with `php -l`.

## Agent attention (local prototype)

Recording a note flags that specific lead and note for later agent review. Select **Number busy** when a call reached a busy line; the plugin can then inspect the lead and internal task schedule and create one new internal call follow-up. The service rejects a conflicting time and duplicate handling of the same item. General notes remain for review rather than causing automatic changes based on their text.

The attention queue and MCP tools are implemented, but periodic Codex execution is not active until the plugin is installed, connected to the owner's account, and a recurring run is configured and tested. The task list is internal to Fieldwork; external calendar availability is not yet connected. No calls, messages, or invitations are sent by this workflow.

## Plugin onboarding

The **Connect Codex** button prepares a local access token, even before plugin installation. The app calls it **Access token prepared** until an installed plugin actually authenticates; then it shows **Plugin access verified**. Installing this local-process plugin does not automatically open Fieldwork. Its first-use setup prompt guides Codex to open the app, sign in, connect if needed, and verify a read-only lookup. Local plugin installation may require restarting the Codex desktop app to discover the marketplace.

A separate hosted Fieldwork plugin uses the live HTTPS MCP server at `https://reach.vumbualabs.com/mcp`, protected by OAuth. It requests separate read, lead-write, and internal follow-up-write permissions. Lead writes can create a lead, change its stage, assign it, or add a factual note. Follow-up writes can create, reschedule, and complete internal tasks. Existing read-only grants stay read-only until the user approves broader access. These tools do not send calls, messages, invitations, or external calendar events. The marketplace's `ON_INSTALL` setting alone does not implement authentication for the local `node` MCP server. See `HOSTED-CONNECTION.md` and `deploy/README.md`.

Agent attention items are queued when lead notes are saved. A five-minute Windows poller is packaged with the hosted plugin. It asks an authenticated Reach endpoint only whether work is waiting; an empty queue causes no model call. Agent scheduling, reporting, and queue resolution happen in one transaction. Agent activity shows reports and guarded reversal requests. Needs your review items, including reversal reviews, now offer Acknowledge & close and Request more work / correction with required notes and durable audit history. Closed work is excluded from polling; correction requests queue separate work without changing internal follow-up tasks. This review flow was deployed on 12 October 2026 after backup; live controls, forms, linked lead navigation, installed checksums and OAuth queue access were verified. See `AGENT-DISPATCH.md` and `AGENT-REVIEW.md`.

## Lead profile corrections

The centered lead profile provides contact editing for authorized owners, managers and accessible salespeople. POST `/api/leads/{id}/profile` accepts `version`, a `changes` object restricted to name, organisation, phone, email, website, facebook, instagram, linkedin, contact_role, location and source, plus required `provenance` and optional `source_activity_id`. Omitted fields stay unchanged; empty strings clear optional fields. Changes are atomic, workspace-scoped and version-checked, synchronize the linked contact, and retain old/new values, actor and source in activity history. Contact URLs must be http(s) without credentials. Organisation corrections do not rename shared organisation records. Run `php backend/migrate.php` before using the updated service.

Hosted MCP discovers one `update_lead_<field>` tool per supported field under `leads:write`; each takes id, current version, value and provenance, with an optional source activity ID. The periodic-agent source skill describes justified user-note corrections and review of ambiguous facts. Notes do not grant authority; supplied corrections remain unverified. Editing and resolving a queued work item are separate transactions, so interrupted agents must inspect history before retrying. The hosted service was upgraded on 12 October 2026. Plugin 0.4.0 packages the profile and agent-work skills; refresh the Vumbua Labs marketplace and installed plugin before using them.

Verification: `php tests/lead-profile.php`, isolated local `php tests/hosted-oauth.php`, the existing integration and agent-automation suites, MCP protocol test and `npm run build`. The profile test creates and removes a disposable workspace and uses synthetic contact values without altering customer records.

## Project senders and rollout

Connect multiple private SMTP accounts under Connected apps, then choose your sender for each project. Email composers and agent consent display that sender. Existing mailbox credentials are migrated without choosing a project; select the intended sender explicitly before sending. Agent tools require `project_id` and never follow a browser's selection. Upgrade the server and plugin/poller together using `deploy/PROJECTS-2026-10-14.md`.

## Public workspace registration — 14 October 2026

Anyone can create an independent workspace and owner at `/signup` or choose **Create account** in the hosted OAuth connection. OAuth registration returns to sign-in, then explicit plugin consent, preserving the registered callback, PKCE challenge, scopes and state. Joining an existing workspace still uses an owner-issued invitation; an email belongs to one account in one workspace. Workspace and owner IDs come only from server-generated values and the authenticated account, never submitted tenant fields.

Signup creates the owner and General project atomically. Email is trimmed/lowercased and the existing global unique index prevents duplicate identities. A session-bound form key and receipt make retries safe; duplicate identities roll back the entire new workspace. Passwords retain whitespace and accept 12–72 bytes to avoid bcrypt truncation; invitation/password-reset setup uses the same limit. Existing password hashes remain usable. Browser and OAuth login share IP and identity throttles (40 per IP and 8 per email per 15 minutes); registration allows 5 valid submissions per IP and 100 globally per hour. Invalid fields are rejected before hashing. Limits use the direct client IP, never untrusted forwarding headers. Reverse-proxy deployments should set their own edge abuse limits because shared proxy IPs can exhaust this application limit.

OAuth forms expire after 30 minutes and require CSRF; sessions reject unknown IDs and rotate on sign-in. Consent pages forbid framing and off-origin form submissions. Authorization codes now bind to the account authentication version, so a password reset also invalidates codes awaiting exchange. The additive migration preserves accounts, workspaces and connections; pre-upgrade unexchanged codes must restart linking.

Verification: run the dedicated loopback server with `FIELDWORK_OAUTH_BASE_URL=http://127.0.0.1:8792`, `FIELDWORK_OAUTH_ALLOW_HTTP_LOCAL=1` and `php -S 127.0.0.1:8792 tests/signup-router.php`, then `php tests/signup.php` with the same environment. It creates and removes disposable local workspaces. Never run it against production. Existing `tests/integration.php` and `tests/hosted-oauth.php` cover invitations, login, roles, OAuth and data boundaries.

Remaining limits: emails are not ownership-verified, automated owner password recovery is not implemented, and each account belongs to one workspace. Registration is protected by application throttles rather than CAPTCHA or email verification. See `deploy/SIGNUP-2026-10-14.md` for self-deployment and acceptance checks. These changes remain local until Peter deploys/publishes them.

## Unreleased Reach research refinement

Lead profiles now open in a full-width tabbed workspace with the business story, rationale, evidence/uncertainty and next action visible by default. Research state and hold are separate from sales stage. Lists support scoped session filters/sorting and mobile cards; Today separates overdue, today and upcoming tasks. Legacy collection context and history remain intact.

Durable research uses additive `lead_research` and `research_requests` tables; `activities.body` widens from TEXT to MEDIUMTEXT to retain bounded old/new research documents. `GET/POST /api/leads/{id}/research` requires the same project and account visibility as the parent lead; collectors receive no private research access. Writes use integer `revision` (0 initially), complete `document`, and actor-bound `request_key`. Identical retries replay; stale revisions return409. `/research-preview` validates a single document without saving. Local agents use `/research-agent`; hosted MCP uses existing lead scopes. Agents cannot mark human review complete. Source schema and field bounds: `backend/lead-research.php`; agent guide: `../sales-workspace-hosted/skills/agent-work/references/research.md`.

Optional structured intake saves the lead graph and research atomically; incomplete readiness rolls back the capture. The Research editor accepts deliberate single-document JSON validation/preview and revision-safe save. No production dossiers are ingested automatically. Unknown budget/authority/intent remain unknown; proposed service/price are suggestions.

Local review: `npm run dev -- --port 5187` with `FIELDWORK_API_PROXY=http://127.0.0.1:8793` targets an alternate loopback API while preserving the default8788. Synthetic fixture: `/tests/reach-fixture.html`; never package test fixtures or `var` credentials. Verification: `php tests/research.php`, `php tests/research-race.php`, `php tests/local-protocol.php`, affected existing suites and `npm run build`. The research HTTP suite targets dedicated test API8793. `tests/run-reach-regressions.mjs` redirects existing8788 tests to8793 without changing them.

Deployment/release notes: `deploy/RESEARCH-2026-10-15.md`. Server packaging does not publish or refresh installed plugin instructions.


### Team and project access

Owners can edit invited/active roles and workspace versus selected-project access from Team & access; project rosters reuse that editor. Membership never assigns all records to a salesperson or grants collector sales powers. Current server authority is enforced in browser, local/hosted MCP and poller paths, including all-project views. Lead reassignment offers deliberate follow-up transfer and affected-request review. See `deploy/TEAM-ACCESS-2026-10-16.md` for backup-first installation, additive migration, safe recovery and manual acceptance. Archives are prepared locally; Peter performs deployment/publication.
