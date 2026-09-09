<?php

/** Run on the osTicket server. Accept a SHA-256 token digest, never a password. */
if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php agent-key.php /path/to/osticket username < token-sha256.txt\n");
    exit(1);
}
$digest = trim(stream_get_contents(STDIN));
if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
    fwrite(STDERR, "Expected the SHA-256 digest of a random 64-character hexadecimal token.\n");
    exit(1);
}
chdir($argv[1]);
require 'main.inc.php';
$staff = Staff::lookup($argv[2]);
if (!$staff || !$staff->isActive()) {
    fwrite(STDERR, "Active agent not found.\n");
    exit(1);
}
$config = new Config('plugin.agent-api.tokens');
if (!$config->update((string) $staff->getId(), $digest)) {
    fwrite(STDERR, "Unable to save the personal token digest.\n");
    exit(1);
}
echo json_encode(['id' => $staff->getId(), 'username' => $staff->getUserName(), 'name' => (string) $staff->getName()]) . "\n";
