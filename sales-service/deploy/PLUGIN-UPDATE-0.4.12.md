# Fieldwork Reach 0.4.12 — prepared scheduling guidance

Prepared locally, not published or installed. The published/installed hosted plugin remains 0.4.11. Local plugin 0.1.2 is unchanged.

This update adds canonical UTC timestamp examples, a bounded format-rejection retry, uncertain-response reconciliation and saved-task verification. It explains the assigned-salesperson requirement for atomic queue scheduling, and how to create an explicitly authorized personal reminder for the requester on an unassigned lead without assigning that lead. It also checks newer notes before recovering a missed callback.

The existing local `.fieldwork-reach/poller.ps1` already has the same immediate canonical-format guidance, so future scheduled worker runs use it without new pairing, task registration or model changes. Updating the installed worker file did not trigger a run. The plugin source poller includes the same prompt for future installations; an eventual plugin install does not automatically replace a separately installed worker script.

The server ZIP includes the complementary timestamp normalization and documented tool schemas. Canonical `.000Z` requests continue to work with the existing production server before that ZIP is deployed. See `SCHEDULING-2026-10-20.md` for validation and the three recovered reminders. There are no new OAuth scopes or endpoint changes.
