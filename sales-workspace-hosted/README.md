# Fieldwork Reach for Codex — 0.4.12 (prepared)

Version 0.4.12 adds canonical scheduling formats, bounded validation recovery, saved-task verification and unassigned-lead guidance. This version is prepared locally and not yet published. Version 0.4.11 added multiple labelled contacts per lead. It requires the matching Reach contact server upgrade. The hosted endpoint and requested OAuth scopes are unchanged.

Create leads with multiple labelled `contacts`, then use `update_lead_contacts` for individual add/update/remove/set_primary operations with the current lead version. Routes support optional name/department, phone/email, role/tag and direct/shared/unknown ownership. Contact ownership never assigns a salesperson. Legacy phone/email tools edit only the selected primary; other contacts remain intact. See `skills/lead-profile/SKILL.md` and the release's contact acceptance guide.

Connect to https://reach.vumbualabs.com/mcp using OAuth. Manage accessible leads and internal tasks, correct eleven profile fields with provenance and retained history, and process queued work with guarded reversal. Version 0.4.5 adds optional, individually authorized lead email through each user's connected SMTP mailbox. No calls or external calendar events are made.

## Install

The commands below require a separately usable Codex CLI in your terminal. Installing the ChatGPT desktop app alone does not guarantee that `codex` is available in PowerShell. Do not use a versioned executable inside the desktop app as a general CLI installation.

Add the existing Vumbua Labs marketplace and install the hosted plugin:

```powershell
codex plugin marketplace add Peternyaga/vumbua-labs-plugins --ref main
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex desktop, open a new chat and connect Fieldwork Reach to your own Fieldwork account. If linking was not offered, run `codex mcp login fieldwork`. Approve only the scopes you need: `leads:read`, `leads:write`, `tasks:write`, and, if you want explicitly authorized agent email, `email:send`. Each person must connect an SMTP mailbox in Fieldwork separately. Ask the plugin to read your current follow-ups to verify the connection. New to Reach? Choose **Create account** in the browser connection to create your independent workspace and owner account, then sign in and review the requested permissions. You can also register at https://reach.vumbualabs.com/signup. To join an existing workspace, ask its owner for an invitation instead. Each email belongs to one account in one workspace; creating an account grants no access to another workspace.

## Update

```powershell
codex plugin marketplace upgrade vumbua-labs
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex desktop and test in a new chat. Reapprove OAuth only if the existing connection is missing or lacks the requested scopes. Server tool changes are discovered from the hosted MCP endpoint; bundled skill/script changes require refreshing the plugin package.

## Release 0.4.6

The Windows poller now resolves the current Codex executable each time it runs. This prevents a desktop-app update from leaving the poller pointed at a removed versioned `codex.exe` path. Re-running `scripts/install-poller.ps1 -Accept -WorkspaceRoot <your existing workspace>` copies the repaired script while preserving the existing pairing and schedule.

## Release 0.4.5

The poller treats authenticated notes as requests for safe internal work, investigates research requests before reporting a blocker, uses the workspace timezone and open internal slots for broad follow-up windows, and avoids recursively queueing agent-authored notes. The new `list_agent_reports` tool helps it inspect prior outcomes. Email sending needs both `email:send` OAuth permission and the lead-specific task checkbox; ordinary notes never authorize outbound email. Publish the server upgrade before refreshing this plugin; the older server does not expose these tools or permission.

## Migrate from fieldwork-local

Install the marketplace package first. Disable `sales-workspace-hosted@fieldwork-local` after the marketplace copy is enabled to avoid duplicate tools. Both packages use the same `fieldwork` server name, HTTPS endpoint and scopes; preserve the existing connection and verify a read before reauthenticating. Do not log out or revoke the existing grant just to change package source.

The optional Windows poller is separate from OAuth. Keep the `.fieldwork-reach` directory and the existing `Fieldwork Reach Poller` scheduled task. Run the new package's `scripts/install-poller.ps1 -Accept -WorkspaceRoot <your existing workspace>` to update its script; when that task and credential exist, the installer preserves pairing. For first-time installation, invoke the setup skill to review permissions and approve pairing in Reach. The poller runs only while the configured Windows user is signed in and the machine is online; work remains queued while offline.

## Release 0.4.2

The optional Windows poller now launches without a visible console. Running the installer again updates an existing scheduled task while preserving its pairing, account, and schedule.

## Release 0.4.1

Moves distribution to the dedicated Vumbua Labs catalog while retaining the same plugin identity and OAuth endpoint. Includes the dedicated lead-profile skill, updated queue correction/recovery guidance and a poller prompt that invokes that workflow. Profile fields: phone, email, website, Facebook, Instagram, LinkedIn, name, organisation, contact role, location and source. Each tool requires current version and factual provenance. Historical values are retained. Ambiguous evidence needs review.

Source: [Fieldwork sales workspace](https://github.com/Peternyaga/fieldwork-sales-workspace/tree/main/sales-workspace-hosted). Distribution: [Vumbua Labs](https://github.com/Peternyaga/vumbua-labs-plugins/tree/main/plugins/sales-workspace-hosted).

## Project context (0.4.7)

All record tools require an explicit project ID. Discover it with `list_projects`; lists support the explicit `all` value. Background work discovers all accessible queued items and uses each returned item's project ID for every subsequent action. Browser selection never changes an agent's scope. Connected SMTP accounts remain private to each user; select a sender for each project in the website. Legacy SMTP credentials are preserved but require an explicit project selection after upgrading. Refresh this plugin together with the server upgrade.

## Self-service signup (0.4.8)

Updates setup guidance for public workspace registration. Existing invited accounts and connections remain supported. Deploy the server package first; installing this source package alone does not add registration to the server. Email ownership verification and automated owner password recovery are not included; keep your password safe and contact the site operator if an owner needs recovery.

## Structured research (0.4.9)

Durable lead research uses `get_lead_research` / `update_lead_research`, existing `leads:read` / `leads:write` scopes, optimistic research revisions and idempotent requests. Agents save draft, ready for human review or hold; human review is recorded in Reach. The bounded document reference is `skills/agent-work/references/research.md`. Optional research can be saved atomically with hosted lead creation. Basic capture remains compatible.

## Team access release (0.4.10)

Adds current project-membership and authority guidance. Role capabilities, project access and lead ownership are separate. `list_team` takes the lead's explicit project and returns eligible recipients who already have access; assignment never grants access and collectors cannot own sales leads. Only workspace owners administer membership. Legacy queued instructions without authenticated requester provenance require human review and fresh instructions.

Access changes can invalidate browser, local-plugin, OAuth and poll credentials. Sign in or reconnect deliberately with current authority; do not automatically expand scopes or recreate a poller. Reassignment may need the user's explicit choices to transfer pending follow-ups and pause affected requests. Research holds and email consent protections remain in place.

Deploy the matching team-access server before using this release. Server tool schemas are discovered from the endpoint; bundled guidance needs a plugin refresh. Endpoint and requested OAuth scopes are unchanged from0.4.9, and poller script behavior is unchanged. Updating the plugin does not itself refresh or trigger an installed Windows scheduled worker. Restart the desktop app and use a fresh chat after updating.
