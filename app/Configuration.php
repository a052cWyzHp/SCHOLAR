<?php
declare(strict_types=1); // keep typed method arguments strict

namespace Scholar;

use Dotenv\Dotenv; // for accessing credentials on .env file
use RuntimeException;

// load and check local settings without putting credentials in application code
class Configuration {
    public static function load(): array {
        $projectDirectory = __DIR__ . '/..'; // .env is at the application root
        $dotenv = Dotenv::createImmutable($projectDirectory); // existing environment values take priority
        $dotenv->safeLoad(); // read .env if present; required() below checks missing values

        // preliminary checks to catch missing settings before PDO attempts a connection
        $dotenv->required(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'])->notEmpty();
        $dotenv->required('DB_PASSWORD'); // key must exist, but a local blank password is allowed
        $dotenv->required('DB_PORT')->isInteger();

        $keys = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
        $values = [];
        foreach ($keys as $key) {
            if (isset($_SERVER[$key])) {
                $values[$key] = $_SERVER[$key];
            } else {
                $values[$key] = $_ENV[$key];
            }
        }

        $database = [
            'host' => $values['DB_HOST'],
            'port' => (int) $values['DB_PORT'],
            'name' => $values['DB_DATABASE'],
            'user' => $values['DB_USERNAME'],
            'password' => $values['DB_PASSWORD'],
        ];

        // names allow only letters/digits/underscore, not extra connection-string settings
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database['name'])) {
            throw new RuntimeException('Invalid database configuration.');
        }
        if ($database['port'] < 1 || $database['port'] > 65535) {
            throw new RuntimeException('Invalid database configuration.');
        }
        // allow normal hostnames/IP addresses, but reject extra PDO connection-string syntax
        if (!preg_match('/\A[a-zA-Z0-9.:-]+\z/', $database['host'])) {
            throw new RuntimeException('Invalid database configuration.');
        }

        return ['database' => $database];
    }
}
