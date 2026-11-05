# Fieldwork Reach 0.4.11 publication — 19 October 2026

Peter explicitly requested publication after receiving the contact upgrade instructions. Published to the existing Vumbua Labs marketplace, https://github.com/Peternyaga/vumbua-labs-plugins, on main at commit [17af30ee503ca2f7c49aa14d35bc809cd57cc471](https://github.com/Peternyaga/vumbua-labs-plugins/commit/17af30ee503ca2f7c49aa14d35bc809cd57cc471). Remote main was reread and matches the commit.

Only five files changed: Reach manifest, README, agent-work and lead-profile skills, and the Reach testing guide. The plugin version is 0.4.11. Timeline Curator's tree remains `87356b3bab5087c895777bfb6d2981daa36385fa`. Marketplace identity, policies, endpoint, OAuth scopes and poller scripts are unchanged.

All 11 hosted plugin source files match the published commit after CRLF/BOM normalization. The two edited skills pass validation and the committed diff has no whitespace errors. Before publication, unauthenticated public discovery at https://reach.vumbualabs.com/mcp returned 34 tools, including create_lead.contacts and update_lead_contacts with add/update/remove/set_primary, current version, project and provenance requirements. No authenticated customer record was read or changed.

Prepared ZIPs were regenerated to include the published README and current release guide. CRC, safe inventory, private-file exclusions, known-secret absence and source/staging/archive parity pass. Current SHA-256:

- Server upgrade (unchanged): `824eafa11e14ad68993a44c273cc644843e1c1b619968a186837d72fe1637eb8`.
- Hosted plugin 0.4.11: `945c9a1c8a175eefed26bd52fe306c4def5f1e4e14c88c3d126735f1c1b2e200`.
- Local plugin 0.1.2 ZIP with the updated release guide: `fc0578473ca67715a20e9474940c2216edfdea6c96da5182c0e2a93c48484eba`.

See `publish/RELEASE-CHECKSUMS-2026-10-19.json` for machine-readable inventory. Local 0.1.2 source remains prepared and unpublished; it is not part of this hosted marketplace publication.

This task did not refresh installed marketplace/plugin caches, restart the desktop app, rotate credentials, broaden grants, update or trigger a scheduled worker, or deploy server files. Update the installed Fieldwork Reach plugin and start a fresh chat to load 0.4.11 guidance. The original extensive dirty source workspace was preserved; the publication commit was made only in the separate marketplace checkout.
