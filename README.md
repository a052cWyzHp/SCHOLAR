# S.C.H.O.L.A.R.

Requirements:
- XAMPP
- Composer

Installed libraries (via Composer):
- Phinx
- phpdotenv

Steps to setup:
1. Clone the repository
2. Open cmd with the repo folder as its directory
3. Enter `composer install` to install dependencies
4. Enter `cp .env.example .env` to generate a .env file
5. Create a mysql database and enter the local credentials in the .env file
6. Enter `php bin/console.php migrate` to generate tables inside the connected database
7. Create admin credentials in the cmd:

This project currently has no default admin login credentials. You may create one through CLI:
```
$env:SCHOLAR_ADMIN_PASSWORD="YourPassword123"
php bin/console.php provision-admin admin admin@example.com
Remove-Item Env:SCHOLAR_ADMIN_PASSWORD

```
3-64 characters for username
Email must be in valid format (e.g. admin@admin.com)
Atleast 12 characters for password


8. Open your browser and navigate to `localhost/(folder name)/public/login.php`