# Team access release — prepared for Peter's self-deployment

This upgrade adds owner-managed role and project-access editing for invited and active members, project rosters/invitation defaults, and eligible lead assignment. Roles remain owner, manager, salesperson and collector. Owners are always workspace-wide; managers cannot administer memberships. Membership alone does not grant a salesperson every lead. No automatic email is sent.

## Installation

Pause the existing poller and avoid website writes during installation. Upload **fieldwork-reach-upgrade.zip** outside `public_html` to `/home/supashop/domains/reach.vumbualabs.com/`, then run:

```sh
cd /home/supashop/domains/reach.vumbualabs.com && unzip -oq fieldwork-reach-upgrade.zip -d fieldwork-upgrade && bash fieldwork-upgrade/install-upgrade.sh
```

The installer backs up the database and application first. It preserves private configuration, runtime sessions and the mail key, enables a website/MCP maintenance gate during replacement/migration, and removes that gate only on success. Record its printed backup path. A failed database backup changes no application files. A later failure restores previous files but **retains maintenance** to prevent an older backend ignoring project restrictions.

## Migration and recovery

Repeatable additive migration adds `users.access_scope` (explicit default `workspace`), `users.membership_version`, `project_memberships` and `membership_audit`. Existing members and existing pending invitations retain workspace-wide behavior. New invitations require an explicit scope; selected scope needs at least one project. Redemption uses current stored configuration; reissuing an invitation preserves that configuration. Existing emails return a conflict, without account duplication or tenant merging. No customer identifiers are seeded.

Membership edits serialize with data writes, use the current version and retain actor, timestamp and before/after role/scope/status in a separate audit without tokens/passwords. Last-owner removal/demotion is blocked. Access removal/demotion/deactivation with affected owned leads, pending follow-ups or requests is blocked with counts. Repair explicitly before retrying. Lead reassignment can explicitly transfer the previous owner's pending follow-ups and pause requests whose author would lose record access. Research holds remain. A project move requires access to both boundaries and eligible lead/follow-up owners, and pauses pending instructions for fresh review; email consent never travels silently.

Membership edits invalidate prior browser/local-plugin/OAuth/poll credentials. Members must sign in or reconnect deliberately with their current permissions. OAuth scopes are unchanged. Do not automatically recreate a poller or broaden grants. Queued execution checks current requester authority; legacy notes lacking authenticated requester provenance need human review and fresh instructions.

**Rollback constraint:** older backend releases ignore selected project access. Never restore an older backend into public service after restricted memberships exist. On failure, leave `public_html/.reach-maintenance` in place and keep the poller paused. Restore a scope-aware build and run its repeatable migration/checks before deliberately removing the maintenance flag. Preserve database backups and subsequent writes; do not reverse schema or restore an old database automatically. Reopening all members to workspace access is a separate explicit owner decision, not a recovery shortcut.

## Manual acceptance after Peter deploys

1. Sign in as owner; confirm existing members and pending invitations remain workspace-wide. Inspect General and other existing projects and legacy lead/research history.
2. Use synthetic teammates/projects: invite selected-project salesperson and collector; review the summary, create/copy the private link, change the pending role/scope and reissue it. Redeem and verify the latest saved scope. Do not use customer addresses or send email.
3. Confirm selected users see only permitted projects, and direct links/all views cannot expose another project. A salesperson sees owned/submitted records; a collector retains capture-only restrictions. Managers see their project roster and cannot invite, promote themselves or grant access.
4. Edit a member in two tabs; stale save must preserve the draft and show current saved values. Verify final-owner safeguard and affected-record blockers. Reassign an owned lead deliberately, including transfer/pause choices, then retry a justified access edit.
5. Check assignment recipients exclude collectors/inactive members and anyone without the lead's project. Check a held lead can receive a research owner without scheduling contact. Check task ownership, project moves, research, counts and background sync.
6. Verify invalidated browser/MCP/poll credentials fail; reconnect only as intended. Review queued work and sender/recipient consent before resuming the existing poller. Mobile forms must scroll, preserve drafts and retain keyboard focus.

Server and plugin archives are separate. Hosted plugin **0.4.10** and local plugin **0.1.1** are prepared/unpublished. Server deployment, marketplace publication, installed cache refresh, poller refresh/trigger and live acceptance are **not performed** by this task. No the team member lead assignment is included.
