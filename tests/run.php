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
    public $assignments = [], $formValid = true, $departmentAllows = true, $loseVisibility = false;
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
    function getAssignmentForm($values, $options) {
        check($options === ['target' => 'agents'], 'Assignment must use the native agent form');
        return new AssignmentForm($values, $this->formValid);
    }
    function assign($form, &$errors, $notify) {
        if (!$this->departmentAllows) { $errors['err'] = 'Department disallows agent'; return false; }
        $this->assignments[] = ['actor' => $GLOBALS['thisstaff']->getId(), 'notify' => $notify];
        $this->staffId = (int) substr($form->values['assignee'][0], 1);
        if ($this->loseVisibility) { $this->visible = false; }
        return true;
    }
}
class TicketQuery
{
    private $id;
    function filter($values) { $this->id = $values['ticket_id']; return $this; }
    function lock() { return $this; }
    function first() { return Ticket::lookup($this->id); }
}
$commitAllowed = true;
function db_autocommit($enabled = true) { return !$enabled || $GLOBALS['commitAllowed']; }
class AssignmentForm
{
    public $values, $valid;
    function __construct($values, $valid) { $this->values = $values; $this->valid = $valid; }
    function isValid() { return $this->valid; }
}
class ClaimForm
{
    function isValid() { return true; }
}
require dirname(__DIR__) . '/api.php';

function expectStatus($expected, $call)
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    try { $call(); } catch (ApiResponse $response) {
        if ($response->status === $expected) { return; }
        throw new RuntimeException("Expected $expected, received {$response->status}");
    }
    throw new RuntimeException("Expected HTTP $expected");
}
function check($condition, $message)
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
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
$payload = new ReflectionMethod(AgentApiController::class, 'assignmentPayload');
$change = new ReflectionMethod(AgentApiController::class, 'changeAssignee');
$assign = function ($body = ['staff_id' => 7], $id = 10) use ($api, $payload, $change) {
    return json_decode($change->invoke($api, $id, $payload->invoke($api, json_encode($body))), true);
};
$fresh = function ($owner = 9) use ($api, $summary) {
    Ticket::$ticket = new Ticket();
    Ticket::$ticket->staffId = $owner;
    $_SERVER['HTTP_IF_MATCH'] = '"' . $summary->invoke($api, Ticket::$ticket)['revision'] . '"';
};
foreach (['', 'null', '[]', '{}', '{', '{"staff_id":true}', '{"staff_id":"7"}',
    '{"staff_id":0}', '{"staff_id":-1}', '{"staff_id":7.5}', '{"staff_id":7,"notify":null}',
    '{"staff_id":7,"notify":1}', '{"staff_id":7,"actor_id":9}',
    str_repeat(' ', 65537) . '{"staff_id":7}'] as $raw) {
    expectStatus(400, function () use ($payload, $api, $raw) { $payload->invoke($api, $raw); });
}
$fresh();
expectStatus(404, function () use ($assign) { $assign(['staff_id' => 7], 999); });
Ticket::$ticket->visible = false;
expectStatus(404, $assign);
$fresh(); Ticket::$ticket->permission = false;
expectStatus(403, $assign);
$fresh(); Staff::$agents[7]->available = false;
expectStatus(403, $assign);
Staff::$agents[7]->available = true;
$fresh(); Ticket::$ticket->open = false;
expectStatus(422, $assign);
$fresh(); unset($_SERVER['HTTP_IF_MATCH']);
expectStatus(400, $assign);
$fresh(); $_SERVER['HTTP_IF_MATCH'] = '"stale"';
expectStatus(400, $assign);
check(!Ticket::$ticket->assignments, 'A stale revision must not invoke assignment');
$fresh();
expectStatus(422, function () use ($assign) { $assign(['staff_id' => 99]); });
Staff::$agents[9]->active = false;
expectStatus(422, function () use ($assign) { $assign(['staff_id' => 9]); });
Staff::$agents[9]->active = true; Staff::$agents[9]->available = false;
expectStatus(422, function () use ($assign) { $assign(['staff_id' => 9]); });
Staff::$agents[9]->available = true;
$fresh(); Ticket::$ticket->formValid = false;
expectStatus(422, $assign);
$fresh(); Ticket::$ticket->departmentAllows = false;
expectStatus(422, $assign);
check(Ticket::$ticket->staffId === 9 && !Ticket::$ticket->assignments, 'Native rejection must preserve the assignee');
$fresh(); $before = $_SERVER['HTTP_IF_MATCH'];
$result = $assign();
check($result['changed'] && $result['previous_staff_id'] === 9 && $result['staff_id'] === 7, 'Transfer from a colleague must use the requested target');
check(Ticket::$ticket->assignments === [['actor' => 7, 'notify' => false]], 'Keep credential owner as actor and suppress alerts by default');
check('"' . $result['revision'] . '"' !== $before, 'Assignment must change the revision');
expectStatus(400, $assign);
$_SERVER['HTTP_IF_MATCH'] = '"' . $result['revision'] . '"';
$result = $assign();
check(!$result['changed'] && ! $result['notification_requested'] && count(Ticket::$ticket->assignments) === 1, 'Current-target assignment must be idempotent without duplicate events');
$fresh(0); $result = $assign();
check($result['previous_staff_id'] === 0 && $result['staff_id'] === 7, 'Unassigned tickets may use the same native assignment path');
$fresh(7); Ticket::$ticket->loseVisibility = true;
$result = $assign(['staff_id' => 9, 'notify' => true]);
check($result['staff_id'] === 9 && $result['notification_requested'], 'Explicit assignment alerts must reach the native method');
check(!Ticket::$ticket->visible && $result['changed'], 'Return a write receipt even when reassignment removes visibility');
$fresh(); $commitAllowed = false;
expectStatus(500, $assign);
$commitAllowed = true;
$fresh(9);
expectStatus(422, function () use ($api) { $api->claim(10); });
expectStatus(422, function () use ($api) { $api->reply(10); });
echo "PASS: {$GLOBALS['checks']} identity, permission, assignment, stale-write, thread and timezone checks\n";
