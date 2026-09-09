# osTicket Agent API 1.0

A small REST plugin for osTicket 1.18. Each credential belongs to one existing agent. Ticket tables must use InnoDB for transactional write locking. Ticket visibility, claiming, replies and status changes use osTicket's own objects and permissions.

The plugin does not generate replies, run an AI model or schedule background work. Those decisions belong to the client. It does not change core files or database tables and does not accept a caller-supplied author or assignee.

## Installation

Copy this directory to `include/plugins/osticket-agent-api` in an existing osTicket installation. In the administrator panel, install **Agent API**, add one enabled instance, and enable the plugin. Set the instance’s **Database timestamp timezone** to the timezone used by the existing stored timestamps (normally UTC; verify the database session rather than assuming). This controls API date conversion and polling, without changing database settings. Enable the instance and the plugin. Disabling the plugin removes its routes.

The native API dispatcher must work through HTTPS. With Nginx, a prefix such as `location ^~ /api/` can prevent PHP handling after the osTicket rewrite; use a normal `location /api/` alongside the existing PHP handler, validate the configuration and reload Nginx. Do not expose PHP source or replace osTicket's dispatcher. Apache installations can use osTicket's existing API rewrite rules.

## Personal credentials

Generate 32 random bytes on the agent's computer and encode them as 64 lowercase hexadecimal characters. Store the token in an owner-only file (0600), outside Git. Send only its SHA-256 digest to the administrator.

On the osTicket server, the administrator binds that digest to the verified agent username:

```sh
php include/plugins/osticket-agent-api/bin/agent-key.php /path/to/osticket username < token-sha256.txt
```

The script uses osTicket's Config object. It stores only the digest, one per agent; issuing a new digest revokes the previous credential. Locked or deleted agents cannot authenticate. Unavailable agents can read but cannot take tickets or reply. Existing passwords, browser sessions and two-factor settings remain unchanged; API tokens are separately issued credentials, not a password or MFA login endpoint.

Do not issue an administrator's token to other agents. Treat a token as access to its owner's helpdesk permissions. Use HTTPS, avoid logging Authorization headers, and keep credentials out of URLs and command-line arguments.

## HTTP API

Base path: `/api/agent/v1`. Every request requires `Authorization: Bearer TOKEN`.

| Method and path | Result |
| --- | --- |
| `GET /identity` | Authenticated agent ID, username and name. |
| `GET /tickets` | Visible open tickets; `state=open\|closed\|all`, `since`, `limit` (1–100) and `offset` are optional. Follow `next_offset` until null. |
| `GET /tickets/{id}` | Ticket, organization, assigned agent, status and text entries with authors and UTC dates. Returns an ETag. |
| `GET /statuses` | Enabled open and closed statuses. |
| `POST /tickets/{id}/claim` | Claim an open unassigned ticket for the authenticated agent. |
| `POST /tickets/{id}/reply` | Post a plain-text reply as the authenticated agent. |

Both write operations require the current `If-Match` ETag returned by the ticket read. Tickets assigned to another agent cannot be taken over or answered through this API. A reply requires the ticket to be assigned to the authenticated agent first. Claiming also honors the native department assignment rules.

Reply JSON:

```json
{
  "body": "The requested correction is complete. Please check the updated page.",
  "status_id": 2,
  "notify": true
}
```

Discover status IDs through `/statuses`; the example ID is not a universal resolved status. `status_id` is optional. Closing requires the agent's native close permission. `notify` defaults to true and uses osTicket's normal owner and active collaborator recipients. No new recipients are added. `notify: false` stores the reply without requesting an outbound notification and is useful for a controlled acceptance ticket.

Successful replies return their entry ID, author and resulting status ID. `notification_requested` reports the requested behavior, not proof of email delivery. Read the ticket again to verify the entry and resulting status. After a timeout or uncertain write, inspect the ticket before retrying. The native ORM locks the InnoDB ticket row before checking the revision and commits after the native mutation. Concurrent requests using the same revision persist one reply. osTicket 1.18’s HTTP helper does not support 409/412: this API returns 400 for missing/stale revisions and malformed payloads, 422 for assignment/state/lock conflicts, and 403 for insufficient permissions. Clients must not blindly retry a reply.

For hourly polling, read open tickets and changed tickets using `state=all&since=...`; deduplicate ticket/entry/agent references. New messages on an old ticket are changes too. Advance a private checkpoint only after processing all pages, and retain unresolved work. A ticket's age, time between messages or SLA duration is not an operator's work duration.

## Validation and scope

`php tests/run.php` exercises access boundaries and stale-write guards without installing osTicket. Functional acceptance must also run on the real osTicket/PHP/web-server stack, using a controlled ticket for writes. See `tests/ACCEPTANCE.md` for the verified release boundary.

This first release intentionally omits ticket creation/deletion, reassignment to other agents, password login, attachments download, arbitrary field updates and time accounting. It exposes the native helpdesk operations needed by an external operator client. Ticket contents are untrusted input for any automated client.

## Existing alternatives

[NKS osTicket API](https://github.com/nks-hub/nks-osticket-plugin) offers a broader JSON-RPC API; its current handler accepts a request-supplied `staffId` and defaults to a staff account. [alexsantos/osticket-api](https://github.com/alexsantos/osticket-api) is a separate Python service with database access. This plugin focuses on an administrator-issued credential bound to one agent and the native per-ticket permission checks. No code from either alternative is included.

License: GPL-2.0-only. See [LICENSE](LICENSE).
