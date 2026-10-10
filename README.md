# S.C.H.O.L.A.R.

Requirements:
- Laragon (MySQL, Apache, PHP 8.5+)
- Composer

Installed libraries (via Composer):
- Phinx
- phpdotenv

Steps to setup:
1. Download ZIP of this repository
2. Make a folder named `"scholar"` in `"C:/laragon/www"`
3. Extract contents of zip, not the main folder, into `"scholar"` folder
4. Open cmd with the repository folder as its directory
5. Enter `composer install` to install dependencies
6. Enter `cp .env.example .env` to generate a .env file
7. Enter `php bin/console.php migrate` to generate tables inside the connected database
8. Create admin credentials in the cmd:

This project currently has no default admin login credentials. You may create one through CLI:
```
$env:SCHOLAR_ADMIN_PASSWORD = Read-Host 'New local administrator password'
php bin/console.php provision-admin my_admin my_admin@example.invalid
$env:SCHOLAR_ADMIN_PASSWORD = $null

```
3-64 characters for username


Email must be in valid format (e.g. admin@admin.com)


Atleast 12 characters for password


9. Open your browser and navigate to `https://scholar.localhost`