<?php
declare(strict_types=1);

use Scholar\Auth\Session;
use Scholar\Auth\Authentication;

// load shared settings, helpers and Composer before processing the form
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();

$identifier = ''; // safe value to keep in the form after an unsuccessful login
$errorMessage = '';
$requestMethod = $_SERVER['REQUEST_METHOD'];
if ($requestMethod !== 'GET' && $requestMethod !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405); // reject methods this page does not support
    exit;
}

if ($requestMethod === 'POST') {
    // check the input type first; a modified request can submit an array instead of text
    if (isset($_POST['identifier']) && is_string($_POST['identifier'])) {
        $identifier = trim($_POST['identifier']);
    }

    $password = null;
    if (isset($_POST['password'])) {
        $password = $_POST['password']; // do not trim passwords; spaces can be part of the password
    }

    $submittedToken = null;
    if (isset($_POST['csrf_token'])) {
        $submittedToken = $_POST['csrf_token'];
    }

    // find invalid input with ordinary checks rather than one large conditional expression
    $validInput = true;
    // if inputted username/email is more than 254 characters
    if ($identifier === '' || strlen($identifier) > 254) {
        $validInput = false;
    }
    // if inputted password isnt a string
    if (!is_string($password)) {
        $validInput = false;
    } else {
        // if it is a string, check if password is empty, more than 72 characters, and has no null bytes before password verification
        if ($password === '' || strlen($password) > 72 || strpos($password, "\0") !== false) {
            $validInput = false;
        }
    }

    if (!Session::verifyCsrf($submittedToken)) {
        http_response_code(403); // this is for preventing CSRF attacks
        $errorMessage = 'The form expired. Please try again.';
    } elseif (!$validInput) {
        http_response_code(422); // if submitted form has invalid usernmae/pass
        $errorMessage = 'Enter a valid username or email and password.';
    } else {
        $pdo = connectDatabase();
        $auth = new Authentication($pdo); // same explicit manager setup as the enrollment project
        $loginSucceeded = $auth->login($identifier, $password);

        if ($loginSucceeded) {
            header('Location: admin.php', true, 303); // show a new GET page after successful POST
            exit;
        }

        http_response_code(401);
        $errorMessage = 'Invalid username/email or password.';
    }
}

// generate CSRF token before the page loads
$csrfToken = Session::csrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S.C.H.O.L.A.R. login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <h1 class="h3 mb-3">S.C.H.O.L.A.R. login</h1>
            <p class="text-secondary">Local development administrator access.</p>

            <!-- show an error only when the form handling above has set one -->
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
            <?php endif; ?>

            <form method="post" action="login.php" class="card card-body">
                <!-- the server checks this token before accepting the login attempt, this is for making sure that whoever clicks Log In is still the same person on the same session -->
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">

                <!-- retype the last typed username/email so the user does not have to retype it themselves, escapeHtml is used so that it is only treated as text on serverside -->
                <div class="mb-3">
                    <label for="identifier" class="form-label">Username or email</label>
                    <input id="identifier" name="identifier" type="text" class="form-control" required maxlength="254"
                           autocomplete="username" value="<?= escapeHtml($identifier) ?>">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input id="password" name="password" type="password" class="form-control" required maxlength="72"
                           autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn-primary">Log in</button>
            </form>
        </div>
    </div>
</main>
</body>

</html>
