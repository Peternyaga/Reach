---
name: fieldwork-reach-setup
description: Set up the Fieldwork Reach plugin and its optional five-minute Windows poller after installation.
---

# Fieldwork Reach setup

Use this when the user runs plugin setup or asks to enable the local Fieldwork background worker. Confirm the hosted Fieldwork connection works first.

For an unconnected user, start the normal Fieldwork OAuth/browser connection. If they have no account, tell them to choose **Create account**, enter their workspace name, name, email and private password in the browser, then sign in and review the requested plugin permissions. They can also create an account at https://reach.vumbualabs.com/signup. Creating an account starts a separate workspace; joining an existing workspace still requires its owner’s invitation. An email has one account in one workspace. Never ask for a password in chat or create an account on the user’s behalf. Existing users should sign in with their current account. If the server has no Create account option, explain that the self-service signup server upgrade has not been deployed; installing the plugin cannot upgrade it. After linking, verify the account with `get_profile` and a read of `list_projects` before offering optional polling setup.

Explain the poller permissions in bullets before running its installer:

- Windows registers a scheduled task for the current user. It checks at sign-in and every five minutes while that user is signed in.
- Each check asks Reach only whether agent work is waiting. Empty checks do not start Codex or use model tokens.
- If work is waiting, the task starts one local Codex run, which may use model tokens and the Fieldwork permissions the user granted separately.
- The poller stores a narrow readiness credential protected for the Windows user in an ignored local folder inside the selected workspace. It cannot read lead details or write to Reach.
- Setup runs the poller script with a process-only PowerShell execution-policy override; it does not change the machine policy.
- The user can remove the scheduled task and revoke polling access in Reach.

After the user authorizes this setup, run `scripts/install-poller.ps1 -Accept -WorkspaceRoot <the current Fieldwork workspace directory>` from the plugin root. The script opens Reach for account sign-in and approval, pairs without exposing the credential in chat, verifies the readiness endpoint, and registers a current-user Windows task. Do not invent success if pairing, task registration, or validation fails. On non-Windows hosts, explain that this installer is Windows-only.

To remove the local task, run `scripts/remove-poller.ps1 -WorkspaceRoot <the same Fieldwork workspace directory>`; it also revokes the poller credential when Reach is available. Do not remove unrelated Windows tasks.

## Explicit project boundary

Record tools require `project_id` from `list_projects`. Identify the authorized project before reading or changing records; ask only if the intended project is ambiguous. Never infer it from another browser's current selection. Read-only list tools accept the explicit `all` value and retain account/role restrictions; every mutation requires one project ID. Queued work carries its project ID; use it for every subsequent call. Local lead moves take `source_project_id` plus the destination `project_id`. A moved lead's linked data follows it, and pending instructions are referred for human review. SMTP credentials stay private to the requesting user; each project has that user's explicit sender selection.
