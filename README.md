# Drupal project

This repository contains a Composer-managed Drupal 11 site. Drupal's web
document root is `web/`. Composer installs Drupal core, contributed projects,
and Drush; project-specific custom modules, themes, and profiles belong in
`web/modules/custom/`, `web/themes/custom/`, and `web/profiles/custom/`.

The current Composer dependencies include Drupal Search API and Drush.

## Requirements

- PHP 8.3 or later, with the extensions required by Drupal (including GD, PDO,
  and XML).
- Composer 2.
- A supported database, such as MySQL/MariaDB or PostgreSQL.
- Apache with `mod_rewrite` enabled, or another web server configured to use
  `web/` as its document root.

## Local setup with XAMPP on Windows

1. Install XAMPP with PHP 8.3 or later and Composer. Make sure the PHP
   executable used by Composer has the required extensions enabled.
2. Open a terminal in the project root and install the locked dependencies:

   ```powershell
   composer install
   ```

   Keep `composer.lock` in version control and use `composer install` for
   repeatable setup.
3. Start Apache and MySQL from the XAMPP Control Panel. Create an empty
   database, for example `drupal_project`, using phpMyAdmin.
4. Configure an Apache virtual host whose `DocumentRoot` points to this
   project's `web` directory. Enable `.htaccess` overrides (`AllowOverride
   All`) for that directory, then add the virtual-host name (for example
   `drupal.local`) to
   `C:\Windows\System32\drivers\etc\hosts` as `127.0.0.1 drupal.local`.
5. If `web/sites/default/settings.php` does not exist, create it from Drupal's
   template in PowerShell:

   ```powershell
   Copy-Item web\sites\default\default.settings.php web\sites\default\settings.php
   ```

   Make `settings.php` writable by the web server during installation.
6. Visit the virtual-host URL (for example `http://drupal.local`) and follow
   Drupal's installation wizard. Select the database driver and enter the
   database name, username, password, and host. The installer will save
   database settings locally; do not commit `settings.php`.
7. After installation, check the site and database connection with Drush:

   ```powershell
   vendor\bin\drush.bat status
   ```

## Useful commands

```powershell
# Verify PHP extensions required by the installed dependencies.
composer check-platform-reqs

# Check Drupal site status (run from the project root).
vendor\bin\drush.bat status
```

## Git and local files

The root `.gitignore` excludes Composer-installed dependencies and generated
site files, plus local settings that may contain database credentials. Do not
add secrets or database dumps to version control. Keep custom project code and
any Drupal configuration exports that the project needs to share under version
control.
