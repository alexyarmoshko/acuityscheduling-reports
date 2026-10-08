# Release 1.0

Date: 2026-10-08

Acuity availability report is a command-line PHP script. It reads calendar
availability from the [Acuity Scheduling API](https://developers.acuityscheduling.com/)
and writes one self-contained HTML page. The page lists calendars on the left and
shows a month view on the right with each day's sessions, booked or not. It loads
nothing from the network, so it works offline and can be shared as a file.

This is the first release.

## Features

- One HTML file per run, with all data, CSS and JavaScript inline. It has
  previous/next month navigation, a calendar picker for narrow screens, a dark
  colour scheme and a print layout.
- Rebuilds each calendar's configured sessions from free slots and booked
  appointments, because the API does not expose configured hours.
- Saves the sessions it sees, so days the API can no longer show (past days, the
  minimum-notice period) still appear in later reports.
- `--render-only` rebuilds the page from saved data with no API calls and no
  credentials.
- `--list` prints the calendar and appointment type IDs needed for the
  configuration.
- `--debug-tutor=ID` prints one calendar's slots per date, each free or booked
  and marked live or stored.
- The script only reads from Acuity. It never sends a write request.

## Requirements

- PHP 8.3 or later, with the `curl`, `json` and `mbstring` extensions.
- An Acuity account with API access, and its user ID and API key.
- Composer, only to run the tests. The script itself has no dependencies.

## Installation

1. Copy the project to the machine that will run it.
2. Create `.env` in the project root:

   ```
   ACUITY_USER_ID=your-numeric-user-id
   ACUITY_API_KEY=your-api-key
   ```

   The values are on Acuity's *Integrations → API → View credentials* page. Put
   one `KEY=value` per line. A line starting with `#` is a comment. Do not add a
   comment after a value: everything after `=` belongs to the value.
3. Copy `config.example.php` to `config.php` and set the values (see below).
4. Run `php acuity-report.php --list` to find the appointment type IDs. Put them
   in `appointment_type_ids`.

Save `.env` and `config.php` as UTF-8. The script rejects UTF-16 files, which
PowerShell's `>` and Notepad's "Unicode" option produce. In PowerShell, use
`Set-Content -Encoding utf8`.

## Configuration

`config.php` returns an array. The script rejects any key not in this table.

| Setting | Value | Meaning |
|---|---|---|
| `season_start`, `season_end` | `YYYY-MM` | First and last month of the report, inclusive |
| `appointment_type_ids` | list of IDs | Appointment types to report. Required for a report; may be empty for `--list` |
| `min_notice_hours` | integer ≥ 0 | Acuity's minimum booking notice, in hours |
| `max_days_ahead` | integer ≥ 1 | How many days ahead clients can book in Acuity |
| `include_calendars` | list of IDs, optional | Calendars to report. Empty means every calendar offering a listed type |
| `exclude_calendars` | list of IDs, optional | Calendars to leave out, applied after `include_calendars` |

Copy `min_notice_hours` and `max_days_ahead` from the account's scheduling
limits. The API does not expose them, and the script uses them to decide which
days a run has seen in full.

The script uses only active appointment types of type `service` that have a
duration. It warns about any other listed type and leaves it out.

## Usage

```bash
php acuity-report.php                 # report: writes out/availability-YYYY-MM-DD_HHMMSS.html
php acuity-report.php --render-only   # same page from saved data, no API calls
php acuity-report.php --list          # calendar and appointment type IDs
php acuity-report.php --help
```

| Option | Purpose |
|---|---|
| `--out=FILE` | Write the report to `FILE`. The default name comes from the run's local time, so each run keeps its own report |
| `--render-only` | Build the page from `data/availability-cache.json` only. No API calls, no `.env`, saved data unchanged |
| `--list` | List calendars and appointment types with their IDs |
| `--debug-tutor=ID` | Print the slots per date for one calendar. Writes nothing |
| `--help` | Show the options |

Progress goes to standard error, and the summary and warnings go to standard
output. A run takes a few minutes, because requests are kept under Acuity's
rate limit.

Exit codes: `0` success, `1` error (one line on standard error says what
failed), `2` invalid option.

**Make the first run before the season starts:** at least `min_notice_hours`
plus one hour before the season's first day begins. That run sees every season
day in full, provided the season ends at least one day before the
`max_days_ahead` limit. The script warns when it does not. Regular runs after
that keep the saved data current.

## Output and saved data

- `out/`: one HTML report per run.
- `data/availability-cache.json`: the saved sessions per calendar and date, with
  the previous version kept as `availability-cache.json.bak`. Store format
  version 1.
- `data/.lock`: lets only one run at a time use `data/`.

`data/` holds history the API cannot return again, so back it up. If the main
file is missing while the `.bak` exists, the script stops and asks you to restore
it. A run writes the report and the saved data only after every request has
succeeded and the new data has passed validation.

## How sessions are rebuilt

Each run reads the free slots for the listed appointment types and all booked
appointments on each calendar. Within the booking window, what the API shows
replaces the saved sessions, including removals. Outside it, such as past days,
the saved sessions stay. A cancelled booking does not remove a session.

A day no run has seen in full is marked **hours unknown**. Its sessions were
seen, but more may exist.

A calendar that no longer exists, or no longer offers a listed type, is removed
from the saved data and the report. The console names it. Excluding a calendar
does not protect its saved data. A run limited by `include_calendars` checks
only those calendars.

## Known limitations

- Blocked-off time does not appear, and nor do slots that Acuity hides through
  resources or scheduling limits.
- Times are computed in Europe/London. The time zone is fixed in
  `SeasonAvailability::TIMEZONE`, and the page header's label is set in
  `ReportRenderer::TIMEZONE_LABEL`.
- A session that crosses midnight, or that spans the autumn clock change, is
  left out with a warning.
- A calendar with 100 or more bookings in one month stops the run, because the
  API response may be truncated.
- A run that takes more than 50 minutes writes nothing, because the booking
  window computed at its start may no longer hold. Run it again.
- If Acuity asks the client to wait more than 60 seconds before retrying, the
  run stops. Try again later.

## Security and privacy

- The API key gives full read and write access to the Acuity account. Keep
  `.env` private and out of version control.
- Error messages and stack traces never include the credentials, the contents
  of `.env` or `config.php`, or API response bodies.
- From the API responses the script uses only calendar IDs and names,
  appointment type details, and booking times and durations. It ignores the
  client fields in booking responses and never stores or prints them.
- The report and the saved data contain calendar names, appointment type names
  and session times.

## Tests

```bash
composer install
composer test
```

All 190 tests pass on PHP 8.3. They run against a fake API and make no network
calls.

## Licence

Copyright (C) 2026 Yak Shaver https://www.kayakshaver.com/

GNU General Public License, version 2 or (at your option) any later version
(SPDX `GPL-2.0-or-later`). The full text is in `LICENSE`. The software comes with
no warranty.
