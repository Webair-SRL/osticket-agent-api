# Release 0.1.0 acceptance

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
