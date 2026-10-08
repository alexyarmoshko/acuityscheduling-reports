# Acuity availability report

A command-line PHP script that reads calendar availability from the
[Acuity Scheduling API](https://developers.acuityscheduling.com/) and writes a
single self-contained HTML page: calendars listed on the left, and a month view
on the right showing each day's free and booked slots, with previous/next month
navigation. The page has no external dependencies, so it works offline and can be
shared as a file.

> **Status:** in development. `--list`, `--spike` and `--debug-tutor` work
> against the API; writing the HTML report is not implemented yet.

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
```

| Option | Purpose |
|---|---|
| `--out=FILE` | Write the report to `FILE` (default `out/availability-YYYY-MM-DD_HHMMSS.html`, from the run's local time, so every run keeps its own report) |
| `--list` | List calendars and appointment types with their IDs |
| `--debug-tutor=ID` | Print free and booked starts per date for one calendar, each marked `live` or `stored <time>`. Writes nothing |
| `--spike` | Save sample API responses to `spike/` (development) |

## How availability is shown

The Acuity API does not expose a calendar's configured hours. The report rebuilds
them from two sources:

- **free slots** that clients can still book, for the configured appointment types;
- **booked appointments** on that calendar (time only; no client details are kept).

Free slots exist only inside the booking window (after the minimum notice and
before the maximum booking horizon set in `config.php`). So each report run
saves, for every day it could see in full, that day's hours (free plus booked)
to `data/availability-cache.json`. Later runs use these saved hours for the
parts of a day the API no longer shows, such as past days.

As a result:

- A day that relies on saved hours is marked **uses saved hours as of** the run
  that saved them. Its bookings always come from the current run.
- A day that could not be read in full, and that no earlier run saw, is marked
  **hours unknown**. Its bookings and any free slots are shown, but further
  hours may be missing.
- Blocked-off time, and slots hidden by Acuity settings such as resources or
  scheduling limits, do not appear.

The `data/` directory holds history the API cannot return again: back it up.
Each run keeps the previous store as `availability-cache.json.bak`. If the main
file goes missing while the `.bak` exists, the script stops and asks you to
restore it. Only one run at a time can use `data/`.

## Tests

```bash
composer install
composer test
```
