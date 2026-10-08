# Acuity availability report

A command-line PHP script that reads calendar availability from the
[Acuity Scheduling API](https://developers.acuityscheduling.com/) and writes a
single self-contained HTML page: calendars listed on the left, and a month view
on the right showing each day's free and booked slots, with previous/next month
navigation. The page has no external dependencies, so it works offline and can be
shared as a file.

> **Status:** in development. `--list` and `--spike` work against the API;
> building the HTML report is not implemented yet.

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
| `--out=FILE` | Write the report to `FILE` (default `out/availability-<date>.html`) |
| `--list` | List calendars and appointment types with their IDs |
| `--debug-tutor=ID` | Print free and booked starts per date for one calendar |
| `--spike` | Save sample API responses to `spike/` (development) |

## How availability is shown

The Acuity API does not expose a calendar's configured hours. The report rebuilds
them from two sources:

- **free slots** that clients can still book, for the configured appointment types;
- **booked appointments** on that calendar (time only; no client details are kept).

As a result:

- Days in the past, inside the minimum-notice period, or beyond the maximum
  booking horizon are marked **hours unknown**. Their bookings are shown, but
  unbooked hours cannot be recovered.
- Blocked-off time, and slots hidden by Acuity settings such as resources or
  scheduling limits, do not appear.

## Tests

```bash
composer install
composer test
```
