<?php
declare(strict_types=1); // keep typed values strict

use Scholar\Auth\Session;

// load shared code, then restore and check the session before any HTML is shown
require_once __DIR__ . '/../app/bootstrap.php';
Session::start();
Session::requireAuthentication(); // sends unauthenticated visitors back to login
$csrfToken = Session::csrfToken(); // token for the logout form
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S.C.H.O.L.A.R. administrative access</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">
<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            <h1 class="h3 mb-3">Administrative access</h1>
            <!-- This "Login succeeded." text is important for the test cases, do not remove -->
            <div class="alert alert-success" role="status">Login succeeded.</div>
            <p>Dashboard WIP, this page is only for verifying authentication for now.</p>

            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrfToken) ?>">
                <button type="submit" class="btn btn-outline-secondary">Log out</button>
            </form>
        </div>
    </div>
</main>
</body>

</html>
