<?php

// Copy this file to config.php and fill in your own values.
// config.php is gitignored; credentials go in .env, not here.

return [
    // First and last month of the report, inclusive (YYYY-MM).
    'season_start' => '2026-01',
    'season_end' => '2026-06',

    // Appointment types to report on. For each calendar, the listed types it
    // offers are queried and their free slots combined.
    // Run `php acuity-report.php --list` to find the IDs.
    'appointment_type_ids' => [],

    // Copy these from your Acuity scheduling limits; the API does not expose them.
    // Clients cannot book fewer than this many hours in advance...
    'min_notice_hours' => 24,
    // ...or more than this many days ahead.
    'max_days_ahead' => 90,

    // Earliest session start and latest session end on any day (HH:MM, local
    // time). Used to decide when a day lies partly outside the booking window.
    'session_window' => ['09:00', '17:00'],

    // Calendar IDs to leave out of the report.
    'exclude_calendars' => [],
];
