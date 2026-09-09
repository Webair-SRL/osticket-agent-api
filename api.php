<?php

/** Native Dispatcher access contract; no inherited controller methods are replaced. */
class AgentApiController
{
    private $staff;
    public static $timezone = 'UTC';

    function access()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_SERVER['HTTPS'] ?? '') !== 'on') {
            Http::response(403, json_encode(['error' => 'HTTPS required']), 'application/json');
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $match)) {
            Http::response(401, json_encode(['error' => 'Personal agent token required']), 'application/json');
        }
        $tokens = new Config('plugin.agent-api.tokens');
        $id = array_search(hash('sha256', $match[1]), $tokens->getInfo(), true);
        $this->staff = $id === false ? null : Staff::lookup((int) $id);
        if (!$this->staff || !$this->staff->isActive()) {
            Http::response(401, json_encode(['error' => 'Personal agent token invalid or revoked']), 'application/json');
        }
        $GLOBALS['thisstaff'] = $this->staff;
        return true;
    }

    function identity()
    {
        return json_encode(['id' => $this->staff->getId(),
            'username' => $this->staff->getUserName(), 'name' => (string) $this->staff->getName()]);
    }

    function tickets()
    {
        $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $state = $_GET['state'] ?? 'open';
        if ($limit === false || $offset === false || !in_array($state, ['open', 'closed', 'all'], true)) {
            Http::response(400, json_encode(['error' => 'Invalid pagination or state']), 'application/json');
        }
        $query = $this->staff->applyVisibility(Ticket::objects(), true);
        if ($state !== 'all') {
            $query->filter(['status__state' => $state]);
        }
        if (isset($_GET['since'])) {
            if (!is_string($_GET['since']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $_GET['since'])) {
                Http::response(400, json_encode(['error' => 'since must be an ISO timestamp with an explicit offset']), 'application/json');
            }
            try {
                $since = new DateTimeImmutable($_GET['since']);
            } catch (Exception $e) {
                Http::response(400, json_encode(['error' => 'Invalid since timestamp']), 'application/json');
            }
            $since = $since->setTimezone(new DateTimeZone(self::$timezone))->format('Y-m-d H:i:s');
            $query->filter(Q::any(['updated__gte' => $since], ['thread__entries__created__gte' => $since],
                ['thread__entries__updated__gte' => $since]));
        }
        $data = [];
        foreach ($query->order_by('ticket_id')->offset($offset)->limit($limit + 1) as $ticket) {
            $data[] = $this->summary($ticket);
        }
        $more = count($data) > $limit;
        return json_encode(['tickets' => array_slice($data, 0, $limit), 'next_offset' => $more ? $offset + $limit : null]);
    }

    function statuses()
    {
        $data = [];
        foreach (TicketStatusList::getStatuses(['states' => ['open', 'closed']]) as $status) {
            if ($status->isEnabled()) {
                $data[] = ['id' => $status->getId(), 'name' => $status->getName(), 'state' => $status->getState()];
            }
        }
        return json_encode($data);
    }

    function ticket($id)
    {
        $ticket = $this->visibleTicket($id);
        $data = $this->summary($ticket);
        $data['entries'] = [];
        foreach ($ticket->getThreadEntries()->copy()->order_by('id') as $entry) {
            $data['entries'][] = ['id' => $entry->getId(), 'type' => $entry->getType(),
                'staff_id' => $entry->getStaffId(), 'poster' => $entry->getPoster(),
                'created_at' => $this->timestamp($entry->getCreateDate()),
                'updated_at' => $entry->getUpdateDate() ? $this->timestamp($entry->getUpdateDate()) : null,
                'body' => (string) $entry->getBody()->convertTo('text'),
                'attachment_count' => $entry->getNumAttachments()];
        }
        header('ETag: "' . $data['revision'] . '"');
        return json_encode($data);
    }

    function claim($id)
    {
        $ticket = $this->writableTicket($id, Ticket::PERM_ASSIGN);
        if ($ticket->getStaffId() == $this->staff->getId()) {
            db_autocommit(true);
            return $this->ticket($id);
        }
        $errors = [];
        $form = $ticket->getClaimForm([]);
        if (!$form->isValid() || !$ticket->claim($form, $errors)) {
            Http::response(422, json_encode(['error' => 'Native claim rejected', 'details' => $errors]), 'application/json');
        }
        if (!db_autocommit(true)) {
            Http::response(500, json_encode(['error' => 'Commit failed; read the ticket before retrying']), 'application/json');
        }
        return $this->ticket($id);
    }

    function reply($id)
    {
        $ticket = $this->writableTicket($id, Ticket::PERM_REPLY);
        if ($ticket->getStaffId() != $this->staff->getId()) {
            Http::response(422, json_encode(['error' => 'Claim the ticket before replying']), 'application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $body = json_decode($raw, true);
        if (strlen($raw) > 65536 || !is_array($body) || !is_string($body['body'] ?? null) || !trim($body['body'])
            || array_diff(array_keys($body), ['body', 'status_id', 'notify'])) {
            Http::response(400, json_encode(['error' => 'Expected body, optional status_id and boolean notify']), 'application/json');
        }
        if (isset($body['notify']) && !is_bool($body['notify'])) {
            Http::response(400, json_encode(['error' => 'notify must be boolean']), 'application/json');
        }
        if (isset($body['status_id']) && (!is_int($body['status_id']) || $body['status_id'] < 1)) {
            Http::response(400, json_encode(['error' => 'status_id must be a positive integer']), 'application/json');
        }
        $status = isset($body['status_id']) ? TicketStatus::lookup($body['status_id']) : $ticket->getStatus();
        if (!$status || !$status->isEnabled() || !in_array($status->getState(), ['open', 'closed'], true)
            || ($status->getState() === 'closed' && !$ticket->checkStaffPerm($this->staff, Ticket::PERM_CLOSE))) {
            Http::response(403, json_encode(['error' => 'Status transition not permitted']), 'application/json');
        }
        if (Banlist::isBanned($ticket->getEmail())) {
            Http::response(422, json_encode(['error' => 'Recipient is blocked by the helpdesk']), 'application/json');
        }
        $lock = $ticket->acquireLock($this->staff->getId(), 2);
        if (!$lock) {
            Http::response(422, json_encode(['error' => 'Ticket locked by another agent']), 'application/json');
        }
        $errors = [];
        $entry = $ticket->postReply(['response' => new TextThreadEntryBody($body['body']),
            'poster' => $this->staff, 'staffId' => $this->staff->getId(), 'reply-to' => 'all',
            'ccs' => [], 'signature' => 'none', 'reply_status_id' => $status->getId()],
            $errors, $body['notify'] ?? true, false);
        $ticket->releaseLock($this->staff->getId());
        if (!$entry) {
            Http::response(422, json_encode(['error' => 'Native reply rejected', 'details' => $errors]), 'application/json');
        }
        if (!db_autocommit(true)) {
            Http::response(500, json_encode(['error' => 'Commit failed; read the ticket before retrying']), 'application/json');
        }
        return json_encode(['entry_id' => $entry->getId(), 'ticket_id' => $ticket->getId(),
            'staff_id' => $entry->getStaffId(), 'status_id' => $ticket->getStatusId(),
            'notification_requested' => $body['notify'] ?? true]);
    }

    private function visibleTicket($id)
    {
        $ticket = Ticket::lookup((int) $id);
        if (!$ticket || !$ticket->checkStaffPerm($this->staff)) {
            Http::response(404, json_encode(['error' => 'Ticket not found or not visible']), 'application/json');
        }
        return $ticket;
    }

    private function writableTicket($id, $permission)
    {
        // Lock before reading the revision. Rejected requests exit without committing.
        db_autocommit(false);
        $ticket = Ticket::objects()->filter(['ticket_id' => (int) $id])->lock()->first();
        if (!$ticket || !$ticket->checkStaffPerm($this->staff)) {
            Http::response(404, json_encode(['error' => 'Ticket not found or not visible']), 'application/json');
        }
        if (!$ticket->checkStaffPerm($this->staff, $permission) || !$this->staff->isAvailable()) {
            Http::response(403, json_encode(['error' => 'Agent lacks permission or is unavailable']), 'application/json');
        }
        if (!$ticket->isOpen() || ($ticket->getStaffId() && $ticket->getStaffId() != $this->staff->getId())) {
            Http::response(422, json_encode(['error' => 'Ticket closed or assigned to another agent']), 'application/json');
        }
        $revision = $this->summary($ticket)['revision'];
        if (($_SERVER['HTTP_IF_MATCH'] ?? '') !== '"' . $revision . '"') {
            Http::response(400, json_encode(['error' => 'Read the ticket again and supply its current ETag']), 'application/json');
        }
        return $ticket;
    }

    private function timestamp($value)
    {
        return (new DateTimeImmutable($value, new DateTimeZone(self::$timezone)))
            ->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    private function summary($ticket)
    {
        $last = $ticket->getThreadEntries()->copy()->order_by('-id')->first();
        $org = $ticket->getOwner()->getOrganization();
        $revision = hash('sha256', json_encode([$ticket->getId(), $ticket->getUpdateDate(),
            $ticket->getStatusId(), $ticket->getStaffId(), $last ? $last->getId() : null,
            $last ? $last->getUpdateDate() : null]));
        return ['id' => $ticket->getId(), 'number' => $ticket->getNumber(), 'subject' => $ticket->getSubject(),
            'status' => ['id' => $ticket->getStatusId(), 'name' => $ticket->getStatus()->getName(), 'state' => $ticket->getState()],
            'staff_id' => $ticket->getStaffId(), 'organization' => $org ? ['id' => $org->getId(), 'name' => $org->getName()] : null,
            'created_at' => $this->timestamp($ticket->getCreateDate()), 'updated_at' => $this->timestamp($ticket->getUpdateDate()),
            'last_entry_id' => $last ? $last->getId() : null, 'revision' => $revision];
    }
}
