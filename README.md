# Acuity availability report

A command-line PHP script that reads calendar availability from the
[Acuity Scheduling API](https://developers.acuityscheduling.com/) and writes a
single self-contained HTML page: calendars listed on the left, and a month view
on the right showing each day's sessions, booked or not, with previous/next month
navigation. The page has no external dependencies, so it works offline and can be
shared as a file.

> **Status:** complete, in final review. All commands work against the API.

## Requirements

- PHP 8.3 or later with the `curl`, `json` and `mbstring` extensions
- An Acuity account with API access, and its API credentials
- Composer, only to run the tests

## Setup

1. Create `.env` in the project root with your API credentials (Acuity:
   *Integrations → API → View credentials*):

   ```
   ACUITY_USER_ID=your-numeric-user-id
   ACUITY_API_KEY=your-api-key
   ```

   One `KEY=value` per line. Lines starting with `#` are comments. Do not put a
   comment on the same line as a value; everything after `=` is part of the value.

   Save `.env` and `config.php` as UTF-8. Windows PowerShell's `>` and Notepad's
   "Unicode" option write UTF-16, which the script rejects. In PowerShell, use
   `Set-Content -Encoding utf8`.

2. Copy `config.example.php` to `config.php` and set your values. The example
   file explains each setting. `appointment_type_ids` may stay empty at first:
   run `php acuity-report.php --list` to find the IDs, then fill them in.

Both `.env` and `config.php` are gitignored. Never commit them. The API key
gives full read/write access to the Acuity account. This script only ever reads.

## Usage

```bash
php acuity-report.php --help
php acuity-report.php                 # writes out/availability-YYYY-MM-DD_HHMMSS.html
php acuity-report.php --render-only   # same page from the saved data, no API calls
```

The script prints progress per calendar to the console. A run takes a couple of
minutes, as requests are kept under Acuity's rate limit. Open the HTML file in
any browser; it needs no network and can be shared as a file.

| Option | Purpose |
|---|---|
| `--out=FILE` | Write the report to `FILE` (default `out/availability-YYYY-MM-DD_HHMMSS.html`, from the run's local time, so every run keeps its own report) |
| `--render-only` | Write the report from `data/availability-cache.json` only: no API calls, no `.env` needed, and the saved data is not changed. Useful when changing the page layout |
| `--list` | List calendars and appointment types with their IDs |
| `--debug-tutor=ID` | Print the slots per date for one calendar, each free or booked and marked `live` or `stored <time>`. Writes nothing |

`include_calendars` and `exclude_calendars` in `config.php` choose the
calendars to report: an empty `include_calendars` means all calendars offering
a listed appointment type, and `exclude_calendars` is applied after it. A run
leaves the saved data of calendars it does not report as it was, unless the
calendar was withdrawn (see below).

## How availability is shown

The report shows the sessions set up in each calendar, booked or not, as one
item per session time. Under each month title it lists the appointment types
that calendar offers.

The Acuity API does not expose a calendar's configured hours. The report rebuilds
them from two sources:

- **free slots** that clients can still book, for the configured appointment types;
- **booked appointments** on that calendar (time only; no client details are kept).

Free slots exist only inside the booking window (after the minimum notice and
before the maximum booking horizon set in `config.php`). So each report run
saves every day's sessions to `data/availability-cache.json`, cumulatively:

- what the API shows now replaces the saved sessions for that part of the day,
  including sessions that have been removed;
- what it can no longer show, such as past days, is kept from earlier runs. A
  cancelled booking does not remove a session.

A day that no run has yet seen in full is marked **hours unknown**: the sessions
shown were seen, but further ones may be missing. Blocked-off time, and slots
hidden by Acuity settings such as resources or scheduling limits, do not
appear.

**Make the first run before the season starts:** at least the minimum notice
plus one hour before the season's first day begins (with 18 hours' notice, by
05:00 the day before). That run sees every season day in full, provided the
season also ends at least one day inside the maximum booking horizon (the
script warns when it does not). Regular runs after that keep the saved data
current.

A calendar that no longer exists, or no longer offers any listed appointment
type, is removed from the saved data and the report; the console lists it. A
run limited by `include_calendars` checks only those calendars. Excluding a
calendar does not protect its saved data: a withdrawn calendar is removed even
when it is in `exclude_calendars`.

The `data/` directory holds history the API cannot return again: back it up.
Each run keeps the previous file as `availability-cache.json.bak`. If the main
file goes missing while the `.bak` exists, the script stops and asks you to
restore it. Only one run at a time can use `data/`.

## Tests

```bash
composer install
composer test
```
