<?php
declare(strict_types=1); // keep typed helper arguments strict

use Scholar\Configuration;
use Scholar\LegacyBaseline;
use Scholar\PhinxCommands;

// only run this test from the terminal, never as a browser endpoint
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/bootstrap.php';

// these inspected credentials are needed only to create a separate disposable database
$adminUser = getenv('SCHOLAR_DB_ADMIN_USER');
$adminPassword = getenv('SCHOLAR_DB_ADMIN_PASSWORD');
if ($adminUser === false || $adminPassword === false) {
    fwrite(STDERR, "Supply inspected temporary DB administrator environment variables for this test.\n");
    exit(2);
}

$configuration = loadConfiguration();
$originalDatabase = $configuration['database'];
$connectionString = 'mysql:host=' . $originalDatabase['host'];
$connectionString .= ';port=' . $originalDatabase['port'] . ';charset=utf8mb4';
$admin = new PDO($connectionString, $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// only this randomly named database may be created or removed by this test
$testDatabase = 'scholar_verify_' . bin2hex(random_bytes(8));
$created = false;
$originalEnvironment = $_ENV;
$originalServer = $_SERVER; // restore both arrays in finally, even if a check fails

try {
    $admin->exec('CREATE DATABASE ' . $testDatabase . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;

    // point only this PHP test process at its new database; do not edit the real .env
    $_SERVER['DB_DATABASE'] = $testDatabase;
    $_ENV['DB_DATABASE'] = $testDatabase;
    $_SERVER['DB_USERNAME'] = $adminUser;
    $_ENV['DB_USERNAME'] = $adminUser;
    $_SERVER['DB_PASSWORD'] = $adminPassword;
    $_ENV['DB_PASSWORD'] = $adminPassword;

    $testConfiguration = Configuration::load();
    if ($testConfiguration['database']['name'] !== $testDatabase) {
        throw new RuntimeException('Immutable environment precedence failed.');
    }
    echo "PASS: phpdotenv preserves supplied environment over .env\n";

    // deliberately submit an invalid port and make sure the library validation rejects it
    $_SERVER['DB_PORT'] = 'invalid';
    $_ENV['DB_PORT'] = 'invalid';
    $rejected = false;
    try {
        Configuration::load();
    } catch (Dotenv\Exception\ValidationException $exception) {
        $rejected = true;
    }
    if (!$rejected) {
        throw new RuntimeException('Invalid configuration accepted.');
    }
    echo "PASS: phpdotenv rejects invalid configuration\n";

    // restore the real server port before applying migrations to the temporary database
    $_SERVER['DB_PORT'] = (string) $originalDatabase['port'];
    $_ENV['DB_PORT'] = (string) $originalDatabase['port'];
    PhinxCommands::run('migrate');
    PhinxCommands::run('migrate'); // a second run must not recreate existing tables
    PhinxCommands::run('seed:run');
    PhinxCommands::run('seed:run'); // a second seed must not duplicate the fixture

    $pdo = connectDatabase();
    $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM phinxlog')->fetchColumn();
    $thesisCount = (int) $pdo->query('SELECT COUNT(*) FROM theses')->fetchColumn();
    $authorCount = (int) $pdo->query('SELECT COUNT(*) FROM thesis_authors')->fetchColumn();
    if ($migrationCount !== 4 || $thesisCount !== 1 || $authorCount !== 2) {
        throw new RuntimeException('Clean database initialization or seed repeatability failed.');
    }
    echo "PASS: fresh Phinx migration and repeated synthetic seed\n";

    // compare table structures, not records, with the saved pre-Phinx foundation
    $manifestText = file_get_contents(__DIR__ . '/../database/legacy_schema.json');
    $manifest = json_decode($manifestText, true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest as $table => $expectedHash) {
        $statement = $pdo->query('SHOW CREATE TABLE ' . $table); // names come from the committed local manifest
        $row = $statement->fetch(PDO::FETCH_NUM);
        $actualHash = LegacyBaseline::schemaHash($row[1]);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('Fresh and legacy schemas differ.');
        }
    }
    echo "PASS: fresh Phinx schema matches the preserved legacy foundation\n";
} finally {
    $_ENV = $originalEnvironment;
    $_SERVER = $originalServer;

    // only remove our own database after successful creation; never scholar_dev or any unrelated database
    if ($created && preg_match('/\Ascholar_verify_[a-f0-9]{16}\z/', $testDatabase)) {
        $admin->exec('DROP DATABASE ' . $testDatabase);
        echo "Temporary test database removed.\n";
    }
}
