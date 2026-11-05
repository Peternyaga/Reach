# Reach team access — 16 October 2026

Authorized: implement and test locally; prepare backup-first server and, if needed, unpublished plugin archives. Peter self-deploys. No live writes, publication, installed cache edits, poller activity or existing assignments in this chat. Preserve all existing dirty work.

## Design and permission matrix

| Role | Record capabilities inside permitted projects | Membership administration |
|---|---|---|
| Owner | Existing full workspace capabilities; always workspace scope | Sole authority to invite, change roles/scope/status and issue account links |
| Manager | Existing management/assignment capabilities in own projects | Read project roster only; no membership, role or workspace grants |
| Salesperson | Existing owned/submitted record permissions, limited by project access | None |
| Collector | Existing capture/submitted record restrictions, limited by project access | None; never assignable as sales owner |

Role and project scope are independent. Membership never assigns records. Scope is `workspace` or `selected` with at least one explicit project. Creating projects requires workspace scope. Assignment requires an active sales-capable recipient with current project access and the current lead version. Moves require both project boundaries and a still-eligible owner; otherwise block and explain reassignment.

Central project authorization covers selected and all views, direct IDs, browser/local/hosted MCP and poller. Current database authority is rechecked; membership writes serialize with authorized operations, increment auth_version and invalidate old credentials. Pending agent execution also rechecks the note requester's current project/record/role authority. Existing email sender privacy and explicit consent checks remain mandatory.

Membership edits use a version, workspace lock, owner safeguard and before/after actor/timestamp audit. Removal, demotion or deactivation with affected owned leads, pending tasks or queued work is blocked with counts and explicit repair guidance; no silent reassignment. Owner can repair assignments before retrying. No new manager delegation.

## Additive migration / recovery

Add explicit users.access_scope default workspace and membership_version default 1; project_memberships and membership_audit tables. Existing active users and pending invitations keep workspace-wide behavior through that explicit default. Pending invitations redeem the latest stored scope; reissue does not replace it. Existing-email invitations fail with an actionable conflict, without account duplication or tenant merging. No email is sent automatically.

Migration must be repeatable and contain no customer identifiers. Older backends ignore scope: rollback to one is unsafe once selected scope is used. Keep maintenance mode and poller paused until a scope-aware build is restored; do not restore old files into public service. Preserve database backups and writes since backup.

## Checkpoint

- Inspected dirty status and prior implementation, supervisor QA, research deployment and plugin 0.4.9 notes. Baseline has extensive existing changes; none reset or stashed.
- Next: implement migration/central authorization and transactional membership API, then UI, isolated regression/security tests, browser review and verified archives.
- No implementation tests run for this change yet. No deployment or live changes performed.

## Validation checkpoint — implementation and browser complete; packaging next

- Shared project authorization now filters browser/hosted/local MCP/all views, recipient rosters, sync, intake and poll readiness; direct records inherit the lead boundary. Current role/access is rechecked under workspace locks. Membership edits invalidate stale credentials without widening OAuth scopes.
- Transactional owner-only membership API, scoped invite/redemption/reissue, before/after audit and last-owner safeguards implemented. Access removal blocks with affected-record counts. Existing owned leads have deliberate reassignment with explicit task transfer / queued-work review choices. Moves check both boundaries and owners. Legacy queue provenance is now required for execution; human review remains available.
- New isolated team suite: **81 PASS** (including OAuth/local/browser/poll credential invalidation and poller project visibility). True concurrent membership writers, last-owner demotions and assignment-versus-scope removal: **6 PASS**.
- Regression pass: integration all PASS; profile21 PASS; agent84 PASS (including new legacy denial); sync integration PASS; stage7 PASS; research55 PASS. Projects initially found a legacy task-name ambiguity: fixed move validation to use the existing linked lead owner ID where applicable; targeted projects rerun **90 PASS**. No failing suite remains. Local MCP protocol baseline + research8 PASS; OAuth32 PASS; signup25 PASS; mail21 PASS with fake transport. JS sync8 PASS; latest production build PASS (53 modules).
- Browser synthetic preview: /tests/team-fixture.html on5186. Desktop1440x1000 and mobile390x844: readable roster/badges, role+scope summary/save, selected project invitation default/create, stale409 draft/current comparison, deliberate reload, focus wrapping, collector exclusion, held-lead assignment, manager read-only roster. Mobile document width390, dialog370 (no horizontal overflow). Reassignment form was subsequently added to lead More actions; verify that final form before handoff.
- Installer simulation: backup-first success, backup failure without site mutation, migration failure restoring files while leaving maintenance enabled: **3 scenarios PASS**. Windows sandbox prevented first Git Bash launch; approved isolated rerun passed after fixture path quoting was corrected. No deployed paths or database accessed by installer tests.
- Source marketplace manifest inspected:0.4.9; remote revisions read only. Hosted source0.4.10 and local source0.1.1 are prepared/unpublished; installed/cache/poller untouched.
- Next exact step: finish final reassignment UI check, run focused team/protocol validation after final narrow privacy/schema edits, build server+plugin ZIPs, run archive verifier and record hashes. No deployment/publication/live permissions or existing assignments performed.

## Final handoff — 16 October 2026

- Final focused team suite **81 PASS**, local protocol baseline + research **8 PASS**, sync integration PASS after final privacy/schema changes. Repeated team login initially hit the existing loopback IP throttle; isolated and restored its test bucket (as signup does), then reran successfully. Production throttle code is unchanged.
- Existing-owned-lead reassignment was reviewed at390x844: selected an eligible salesperson, explicitly transferred pending follow-ups and paused affected requests, reviewed summary and saved in the synthetic fixture. Fixed fixture-only long JSON notice overflow; final viewport390, document375 with scrollbar. Desktop/mobile screenshots: ../tmp/reach-team-desktop-2026-10-16.jpg and ../tmp/reach-team-mobile-2026-10-16.jpg. No production UI data was used.
- Backup-first server and separate unpublished hosted/local plugin archives built. Final verifier PASS: CRC/inventory, safe paths, known-secret/private-file exclusions, backend/dist/plugin source parity, versions and LF installer. Hosted endpoint/scopes equal installed0.4.9 parsed configuration.
- `fieldwork-reach-upgrade.zip`: 201340 bytes, 38 files, SHA256 `2d5192344e1efe1650fb3d05b38c915cf782846688092cdbcf6f4e6ea9d4c5d5`.
- `fieldwork-reach-plugin-0.4.10.zip`: 25920 bytes, 12 files, SHA256 `3bec54e125a101095233aa5592f072741b2f0846da70df415022719c488ca732`.
- `fieldwork-local-plugin-0.1.1.zip`: 15332 bytes, 7 files, SHA256 `c007d40e87a5aeec93a8534284c81d07810b4b29f261469a8c63152788f2363e`.
- Deployment/recovery/manual acceptance: deploy/TEAM-ACCESS-2026-10-16.md. Hosted publication/install guide: deploy/PLUGIN-UPDATE-0.4.10.md. Older backend rollback must remain in maintenance because it ignores selected-project restrictions.
- Complete for local handoff. No deployment, publication, installed cache edits, live data/access changes, poller refresh/trigger, real email or the team member lead assignments performed. Peter self-deploys and performs live acceptance. No continuation automation was needed or created.
- Practical retained limits: membership administration is owner-only; access changes with affected records block until deliberate repair. Legacy task owners stored as text must resolve to an active, unambiguous member unless an existing linked lead owner identifies them. Legacy requests without authenticated requester provenance require human review and fresh instructions. No real email deliverability assertion is made.

- Final additional safeguard: membership edits invalidate approved-but-unfinished poll pairings as well as issued poll tokens. Team suite now **81 PASS**; fresh server ZIP rebuilt and all three archives reverified with the hashes above. No unfinished authorized implementation work remains.


## Published and local installation refreshed — 16 October2026

Peter confirmed the server deployment and explicitly authorized publishing the plugin and syncing local installation. Published Fieldwork Reach0.4.10 to Vumbua Labs main at5107e68, refreshed the marketplace and installed/enabled0.4.10 locally. All11 hosted plugin contents match workspace and committed release with Windows line-ending/BOM normalization; local Sales Workspace0.1.1 remains byte-identical across6 files. The fieldwork-local hosted duplicate remains disabled. Public live tool discovery confirms the deployed team/assignment schema. No live lead changes, OAuth permission expansion or poller execution occurred. Restart desktop and open a fresh chat to load the refreshed instructions. Full evidence: deploy/PLUGIN-PUBLICATION-2026-10-16.md.
