# Agent work and email upgrade — prepared 13 October 2026

This is a local, **not deployed** combined release. It includes the earlier lead-profile, cross-device refresh, and optional-review-decision changes as well as this agent/email work. No real customer email was sent during testing. Package SHA-256 values are in `PROGRESS.md`.

## Install the website and backend yourself

Upload `sales-service/publish/fieldwork-reach-upgrade.zip` to `/home/supashop/domains/reach.vumbualabs.com/` in DirectAdmin, outside `public_html`. Keep its filename. In the DirectAdmin terminal run:

```bash
cd /home/supashop/domains/reach.vumbualabs.com && unzip -oq fieldwork-reach-upgrade.zip -d fieldwork-upgrade && bash fieldwork-upgrade/install-upgrade.sh
```

The installer backs up the database, site and backend, then applies repeatable migrations. It preserves `fieldwork/config.local.php`, creates `fieldwork/var/mail.key` with restricted permissions if absent, and backs up an existing key. Keep the key with database backups: without it, stored SMTP passwords cannot be decrypted. File rollback is automatic if installation fails; schema rollback needs a deliberate database restore. Do not place the key or private config in `public_html`.

Reload the site once on every open device. Under **Connected apps → Your email account**, each sender enters their own SMTP hostname, port 587 with STARTTLS or port 465 with TLS, username, password, From email and From name. Passwords never appear on GET responses. A provider can reject a From address that does not belong to the authenticated mailbox. HostAfrica/DirectAdmin users should obtain their actual SMTP host and credentials from their hosting panel; do not enter a Roundcube page URL as the SMTP host. The server needs outbound access to the chosen SMTP port and a valid certificate for that hostname.

Open a lead with a verified email. The collapsed **Email this lead** section contains a plain-text composer and email status history. **Send email now** sends immediately; do not test it on a real lead unless you intend to contact that lead. A failed/uncertain send is marked `needs_check` and never retried automatically; check the provider's Sent folder and the lead's email history before any manual retry. There is no inbound mail sync, campaign sending, attachments or scheduled outbound email in this release.

## Publish and refresh the plugin yourself

The server archive does not update Codex's installed plugin. Separately prepared source archive: `sales-service/publish/fieldwork-reach-plugin-0.4.5.zip`. Extract its `sales-workspace-hosted/` directory into the existing [Vumbua Labs marketplace](https://github.com/Peternyaga/vumbua-labs-plugins/tree/main/plugins/sales-workspace-hosted) as `plugins/sales-workspace-hosted`, replacing the prior plugin files while preserving the marketplace catalog. Verify its `.codex-plugin/plugin.json` version is `0.4.5`, review the marketplace diff, then commit and push it yourself. No marketplace source was changed by this task.

After the marketplace update is available, on each poller computer:

```powershell
codex plugin marketplace upgrade vumbua-labs
codex plugin add sales-workspace-hosted@vumbua-labs
```

Restart Codex. Refresh the existing poller script without losing its pairing by running the plugin's `scripts/install-poller.ps1 -Accept -WorkspaceRoot <your existing workspace>` as described in the plugin README. The bundled skill and poller prompt are needed for the new handling of signed-in notes, research, previous reports and broad follow-up windows. Until that refresh, the installed poller retains the older instructions even after the server upgrade.

The new plugin requests `email:send` as a **separate OAuth scope**. Existing connections lacking it must be reauthorized through the normal Fieldwork OAuth screen before the agent can send. A signed-in user must also connect their own mailbox and check **Allow this specific task to email …** when queuing that one agent task. The address is captured then and must still match when the agent sends. Ordinary notes, old tasks and the OAuth scope by themselves do not authorize outbound email. Agent work that can be handled internally remains available without email permission.

## Local verification completed and remaining checks

Local schema migration, PHP syntax, production frontend build, email unit/integration tests with a fake transport, OAuth/MCP scope checks, agent-work, lead-profile, general integration, workspace-sync PHP and JavaScript suites passed. The archive was inspected for required files and absence of local credentials. Real SMTP delivery with a provider account, production migration, production poller behavior and physical cross-device UI were deliberately **not** exercised; verify those after installing. Test email delivery only to a mailbox you control and only when you intend to send.
