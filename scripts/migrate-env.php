<?php

declare(strict_types=1);

use CleanLink\ConfigMigrator;

require_once dirname(__DIR__) . '/app/ConfigMigrator.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/migrate-env.php /path/to/.env\n");
    exit(2);
}

try {
    $migrated = ConfigMigrator::migrateEnv($argv[1]);
    if ($migrated) {
        fwrite(STDOUT, "Migrated the plaintext password to a password hash and invalidated old login cookies.\n");
        fwrite(STDOUT, "The existing password remains valid; rotate it if it is shorter than 20 characters.\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
