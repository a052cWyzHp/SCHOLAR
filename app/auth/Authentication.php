<?php
declare(strict_types=1); // require the declared argument types

namespace Scholar\Auth;

use PDO;

// checks the credentials and establishes the logged-in identity
class Authentication {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function login(string $identifier, string $password): bool {
        // separate placeholders allow username or email login with prepared values
        $statement = $this->pdo->prepare("
            SELECT id, password_hash
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1
        ");
        $statement->execute(['username' => $identifier, 'email' => $identifier]);
        $user = $statement->fetch();

        // random dummy password hash that is not tied to any account
        // this is for preventing timing attacks by providing a default hash so that verification has the same computational cost and timing regardless if the user exists or not
        // therefore, attackers will not be able to guess which users exist based on computational timing so that they can target a specific username/email then use password attacking methods
        $passwordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if ($user) {
            $passwordHash = $user['password_hash'];
        }

        $passwordMatches = password_verify($password, $passwordHash);
        if (!$user || !$passwordMatches) {
            return false; // if invalid username or password, return false
        }

        // replace the old ID so the pre-login session cannot restore authenticated access
        session_regenerate_id(true);
        $_SESSION = []; // discard the previous anonymous session's values
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // new form token after login
        return true;
    }
}
