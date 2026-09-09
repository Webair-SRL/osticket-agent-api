<?php

// Focused transport tests. Native osTicket integration is tested separately.
class ApiResponse extends Exception
{
    public $status;
    function __construct($status, $body) { parent::__construct($body); $this->status = $status; }
}
class Http
{
    static function response($status, $body) { throw new ApiResponse($status, $body); }
}
class Config
{
    static $values = [];
    function __construct($namespace) {}
    function getInfo() { return self::$values; }
}
class Staff
{
    static $agents = [];
    public $id, $active = true, $available = true;
    function __construct($id) { $this->id = $id; }
    static function lookup($id) { return self::$agents[$id] ?? null; }
    function getId() { return $this->id; }
    function getUserName() { return 'agent-' . $this->id; }
    function getName() { return 'Agent ' . $this->id; }
    function isActive() { return $this->active; }
    function isAvailable() { return $this->available; }
}
class Entries
{
    public $items, $descending = false, $limit = null;
    function __construct($items) { $this->items = $items; }
    function copy() { return clone $this; }
    function order_by($field) { $this->descending = $field === '-id'; return $this; }
    function first() { $this->limit = 1; return $this->items[count($this->items) - 1]; }
}
class Entry
{
    public $id;
    function __construct($id) { $this->id = $id; }
    function getId() { return $this->id; }
    function getUpdateDate() { return '2026-01-01 12:00:00'; }
}
class Status
{
    function getName() { return 'Open'; }
}
class Owner
{
    function getOrganization() { return null; }
}
class Ticket
{
    const PERM_ASSIGN = 'ticket.assign';
    const PERM_REPLY = 'ticket.reply';
    const PERM_CLOSE = 'ticket.close';
    static $ticket;
    public $visible = true, $permission = true, $open = true, $staffId = 0, $claims = 0, $entries;
    function __construct() { $this->entries = new Entries([new Entry(1), new Entry(2)]); }
    static function lookup($id) { return $id == 10 ? self::$ticket : null; }
    static function objects() { return new TicketQuery(); }
    function checkStaffPerm($staff, $permission = null) { return $this->visible && ($permission === null || $this->permission); }
    function isOpen() { return $this->open; }
    function getStaffId() { return $this->staffId; }
    function getThreadEntries() { return $this->entries; }
    function getOwner() { return new Owner(); }
    function getId() { return 10; }
    function getUpdateDate() { return '2026-01-01 12:00:00'; }
    function getCreateDate() { return '2026-01-01 11:00:00'; }
    function getStatusId() { return 1; }
    function getStatus() { return new Status(); }
    function getState() { return 'open'; }
    function getNumber() { return '100010'; }
    function getSubject() { return 'Example'; }
    function getClaimForm($values) { return new ClaimForm(); }
    function claim($form, &$errors) { $this->claims++; return false; }
}
class TicketQuery
{
    private $id;
    function filter($values) { $this->id = $values['ticket_id']; return $this; }
    function lock() { return $this; }
    function first() { return Ticket::lookup($this->id); }
}
function db_autocommit($enabled = true) { return true; }
class ClaimForm
{
    function isValid() { return true; }
}
require dirname(__DIR__) . '/api.php';

function expectStatus($expected, $call)
{
    try { $call(); } catch (ApiResponse $response) {
        if ($response->status === $expected) { return; }
        throw new RuntimeException("Expected $expected, received {$response->status}");
    }
    throw new RuntimeException("Expected HTTP $expected");
}
function check($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$token = str_repeat('a', 64);
Config::$values = [7 => hash('sha256', $token)];
Staff::$agents = [7 => new Staff(7), 9 => new Staff(9)];
$_SERVER = ['HTTPS' => 'on'];
expectStatus(401, function () { (new AgentApiController())->access(); });
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('b', 64);
expectStatus(401, function () { (new AgentApiController())->access(); });
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['HTTPS'] = 'off';
expectStatus(403, function () { (new AgentApiController())->access(); });
$_SERVER['HTTPS'] = 'on';
Staff::$agents[7]->active = false;
expectStatus(401, function () { (new AgentApiController())->access(); });
Staff::$agents[7]->active = true;
$api = new AgentApiController();
$api->access();
check(json_decode($api->identity(), true)['id'] === 7, 'Token must select its own agent');
check($GLOBALS['thisstaff']->getId() === 7, 'Native lifecycle author must match the token');
Ticket::$ticket = new Ticket();
expectStatus(404, function () use ($api) { $api->claim(999); });
Ticket::$ticket->visible = false;
expectStatus(404, function () use ($api) { $api->claim(10); });
Ticket::$ticket->visible = true;
Ticket::$ticket->permission = false;
expectStatus(403, function () use ($api) { $api->claim(10); });
Ticket::$ticket->permission = true;
Staff::$agents[7]->available = false;
expectStatus(403, function () use ($api) { $api->claim(10); });
Staff::$agents[7]->available = true;
Ticket::$ticket->staffId = 9;
expectStatus(422, function () use ($api) { $api->claim(10); });
expectStatus(422, function () use ($api) { $api->reply(10); });
Ticket::$ticket->staffId = 0;
Ticket::$ticket->open = false;
expectStatus(422, function () use ($api) { $api->claim(10); });
Ticket::$ticket->open = true;
expectStatus(400, function () use ($api) { $api->claim(10); });
$_SERVER['HTTP_IF_MATCH'] = '"outdated"';
expectStatus(400, function () use ($api) { $api->claim(10); });
check(Ticket::$ticket->claims === 0, 'Rejected requests must not invoke a native write');
check(Ticket::$ticket->entries->limit === null, 'Revision lookup must not truncate the cached ticket thread');
Config::$values[7] = hash('sha256', str_repeat('c', 64));
expectStatus(401, function () { (new AgentApiController())->access(); });
$summary = new ReflectionMethod(AgentApiController::class, 'summary');
AgentApiController::$timezone = 'Europe/Rome';
$data = $summary->invoke($api, Ticket::$ticket);
check($data['created_at'] === '2026-01-01T10:00:00+00:00', 'Winter database timestamps must convert to UTC');
check($data['updated_at'] === '2026-01-01T11:00:00+00:00', 'Updated timestamp must use the same timezone');
$timestamp = new ReflectionMethod(AgentApiController::class, 'timestamp');
check($timestamp->invoke($api, '2026-07-01 12:00:00') === '2026-07-01T10:00:00+00:00', 'Summer offset must follow the configured timezone');
echo "PASS: 20 identity, permission, stale-write, thread and timezone checks\n";
