# Push notification setup

Musicball's push notifications include personalized advance reminders and deadline-reached notices for unfinished song submissions and voting, plus admin-only gameplay alerts for automatic playlist and voting timing fallbacks.

## Server requirements

- PHP 8.2 or newer with `curl`, `mbstring`, and `openssl`
- HTTPS
- Composer dependencies installed with `composer install --no-dev --optimize-autoloader`
- A scheduler that can run PHP every 15 minutes

## Database setup

Run `push_notifications_setup.sql` against each target database. It creates separate live and QA subscription and delivery tables. QA subscriptions start empty and must never be populated by copying live subscription rows.

## VAPID configuration

Generate one VAPID key pair using the included one-time setup command. Keep the pair stable; changing it invalidates existing browser subscriptions.

For QA-only configuration:

```text
php push_configure.php --enable-qa
```

For the live application:

```text
php push_configure.php --enable-live
```

The command creates the ignored `config/push_secrets.php` file and refuses to overwrite an existing key pair. It never prints either key.

Provide these values through environment variables:

- `MUSICBALL_PUSH_ENABLED=1`
- `MUSICBALL_PUSH_QA_ENABLED=1` only where QA push testing is desired
- `MUSICBALL_PUSH_VAPID_PUBLIC_KEY`
- `MUSICBALL_PUSH_VAPID_PRIVATE_KEY`
- `MUSICBALL_PUSH_VAPID_SUBJECT=https://musicball.net`

For manually managed local configuration, the same values may be defined with `_LOCAL` appended to their names in the ignored `config/push_secrets.php` file.

## Scheduler

Run live reminders every 15 minutes:

```text
php /path/to/musicball/push_scheduler.php --mode=live
```

QA is always explicit and uses only QA tables:

```text
php /path/to/musicball/push_scheduler.php --mode=qa
```

Add `--dry-run` to either command to count eligible device reminders without sending or recording them.

The reminder scheduler checks a 30-minute lookback window for deadlines. With the 15-minute cron cadence, this delivers one deduplicated deadline-reached notice to each subscribed player who is still incomplete without replaying stale notices after an extended scheduler outage.

The playlist scheduler also advances gameplay phases and uses the shared push service for timing fallbacks. In `Wait for everyone`, song submissions fall back 12 hours before Votes Due, while voting falls back 12 hours before the following round's Songs Due. With partial participation, the scheduler changes the league to `Build at Songs Due`, advances with the available work, and alerts subscribed admin devices. Push delivery is best-effort and never blocks the setting change or phase transition.
