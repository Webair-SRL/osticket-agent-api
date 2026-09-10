# Release acceptance

## Release 1.1.0 — assignment endpoint

Checked locally on 2026-09-10: 66 transport/contract checks pass, including target validation, native form rejection, unavailable agents, department rejection, permission and revision guards, transfer from another agent, default suppressed alerts, idempotent assignment, changed revision, loss of caller visibility and commit failure. The existing claim/reply guards remain covered. All plugin PHP files pass syntax validation.

Verified on the real osTicket 1.18.3, Nginx, PHP 8.4.23 FPM and InnoDB installation on 2026-09-10. The three runtime files were backed up and deployed without reinstalling; server SHA-256 hashes match commit `63da1755138ab9e1adad284b1e2036545ff5a304`. The existing internal acceptance ticket was used; no customer ticket was changed.

| Check | Result |
| --- | --- |
| Assign a closed ticket | PASS; HTTP 422, no mutation |
| Assign the current agent with a fresh revision | PASS; `changed: false`, no extra assignment event |
| Transfer to another eligible agent | PASS; receipt and independent HTTPS read agree |
| Take over from that agent onto the credential owner | PASS; receipt and independent HTTPS read agree |
| Reuse the revision from before reassignment | PASS; HTTP 400 |
| Existing claim/reply on a ticket assigned to someone else | PASS; both return HTTP 422 |
| Native assignment history | PASS; exactly two assignment events, both attributed to the credential owner; self-assignment recorded as a claim |
| Public conversation | PASS; all four existing entries preserved, no new reply or note |
| Assignment alerts | PASS; both transfers requested `notify: false` |
| Fixture restoration | PASS; native resolved status and original assigned agent restored and reread |

The source-only tests cover additional rejection cases; they are distinct from the live cases above. No email-delivery claim is made. The initial unavailable SSH connection was resolved before deployment and acceptance.

## Release 1.0.0

Verified on 2026-09-09 using an existing osTicket 1.18.3 installation, Nginx, PHP 8.4 FPM and InnoDB. This was a real HTTPS server test; the local transport tests do not replace it.

| Check | Result |
| --- | --- |
| Install and enable through native Plugin/PluginInstance objects | PASS; one enabled instance visible in the administrator panel |
| Missing personal credential | PASS; HTTP 401 |
| Personal identity and ticket visibility | PASS; authenticated agent and native visible open queue |
| Complete paginated open queue | PASS; two records per page, no missing or duplicate IDs |
| Changes on a closed ticket | PASS; found through state=all and since |
| Claim an unassigned controlled ticket | PASS; native assignment and browser event show credential owner |
| Ticket assigned to another agent | PASS; rejected, assignment unchanged on reread |
| Native reply and complete thread | PASS; recorded entry, correct author and existing entries preserved |
| Two simultaneous replies with the same revision | PASS; exactly one new response, the other request rejected |
| Native resolved status | PASS; reply, persisted status and closed-by author verified in API and browser |
| Source timezone differs from PHP default | PASS; configured source timezone yields correct UTC offsets; since uses the source timezone |
| Notifications suppressed for acceptance | PASS; writes used notify=false |
| Core source changes | None; plugin uses the existing API signal/dispatcher and native models |

The controlled test ticket remains closed for traceability. No customer ticket was used for successful test writes. Outbound email delivery was not exercised: notify=true delegates to the existing osTicket notification path, and notification_requested is not delivery confirmation.

The offline suite additionally covers rejected missing/wrong/revoked credentials, inactive or unavailable agents, insufficient permissions, closed/invisible tickets, missing/stale revisions, cached-thread preservation, and winter/summer timestamp conversion. Run `php tests/run.php` and lint all PHP files. CI repeats these checks on PHP 8.2 and 8.4.

Before deployment elsewhere, verify InnoDB, the actual database timestamp timezone, the HTTPS API rewrite, personal key binding and native role visibility on that installation. No claim is made for untested osTicket releases or PHP versions.
