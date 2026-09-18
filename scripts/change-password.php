<?php

declare(strict_types=1);

use CleanLink\ConfigMigrator;

require_once dirname(__DIR__) . '/app/ConfigMigrator.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: printf '%s' PASSWORD | php scripts/change-password.php /path/to/.env\n");
    exit(2);
}

$password = stream_get_contents(STDIN);
if (!is_string($password)) {
    fwrite(STDERR, "Unable to read the password.\n");
    exit(1);
}

try {
    ConfigMigrator::changePassword($argv[1], $password);
    fwrite(STDOUT, "Password updated and existing login cookies invalidated.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
