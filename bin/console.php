<?php
declare(strict_types=1); // keep typed method arguments strict

use Scholar\AdminProvisioner;
use Scholar\LegacyBaseline;
use Scholar\PhinxCommands;

// these commands belong in PowerShell, not in browser requests
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// argv holds terminal arguments; argv[1] is the command after this script's filename
$command = '';
if (isset($argv[1])) {
    $command = $argv[1];
}
$allowedCommands = ['check-db', 'migrate', 'migration-status', 'adopt-legacy', 'seed', 'provision-admin'];
if (!in_array($command, $allowedCommands, true)) {
    fwrite(STDERR, "Usage: php bin/console.php check-db|migrate|migration-status|adopt-legacy|seed|provision-admin\n");
    exit(2); // an invalid command is a usage error
}

$pdo = connectDatabase();
switch ($command) {
    case 'check-db':
        // this query also confirms that the configured connection reaches the database server
        $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
        echo 'Connected to development database; server ' . $serverVersion . "\n";
        break;

    case 'migrate':
        PhinxCommands::run('migrate'); // apply only migrations not recorded as applied
        break;

    case 'migration-status':
        PhinxCommands::run('status'); // show applied and pending migration versions
        break;

    case 'adopt-legacy':
        // one-time transition for an old baseline database, never routine database setup
        $existingLedger = $pdo->query("SHOW TABLES LIKE 'phinxlog'")->fetchColumn();
        if ($existingLedger) {
            $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn();
            if ($migrationCount !== 0) {
                throw new RuntimeException('Phinx history already exists; do not adopt again.');
            }
        }

        LegacyBaseline::assertMatches($pdo); // check history and actual structure before marking anything applied
        PhinxCommands::run('migrate', ['--fake' => true, '--target' => '20261003000400']);
        echo "Verified legacy baseline adopted without replaying DDL. Original ledger retained.\n";
        break;

    case 'seed':
        PhinxCommands::run('seed:run'); // insert fictional fixtures through the library's seed runner
        break;

    case 'provision-admin':
        // read the public account identifiers from arguments, but never take a password on the command line
        $username = '';
        $email = '';
        if (isset($argv[2])) {
            $username = trim($argv[2]);
        }
        if (isset($argv[3])) {
            $email = trim($argv[3]);
        }
        $password = getenv('SCHOLAR_ADMIN_PASSWORD'); // supplied temporarily through the process environment
        if ($password === false) {
            fwrite(STDERR, "Supply SCHOLAR_ADMIN_PASSWORD through a temporary environment variable.\n");
            exit(2);
        }

        try {
            $provisioner = new AdminProvisioner($pdo);
            $provisioner->createAdministrator($username, $email, $password);
            echo "Local development administrator created. Existing accounts are never overwritten.\n";
        } catch (InvalidArgumentException $exception) {
            // report the expected input rules without echoing the password or a raw database error
            fwrite(STDERR, "Use a 3-64 character username (letters, digits, underscore, dot, hyphen), a valid email, and a 12-72 byte password.\n");
            exit(2);
        } finally {
            putenv('SCHOLAR_ADMIN_PASSWORD'); // clear this process's temporary password even if creation failed
        }
        break;
}
