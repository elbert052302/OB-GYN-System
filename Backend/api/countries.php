<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$countries = [];
foreach (gc_country_codes() as $countryCode) {
    $timeZone = gc_default_time_zone_for_country($countryCode);
    if ($timeZone !== null) {
        $countries[] = ['code' => $countryCode, 'timeZone' => $timeZone];
    }
}

gc_respond(200, ['countries' => $countries]);
