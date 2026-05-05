# Musicball Discord SSO install notes

## Files changed / added

- `index.php` replaces passcode login with Discord-only login.
- `integrations/discord/login.php` starts Discord OAuth.
- `integrations/discord/callback.php` completes Discord OAuth and creates the Musicball session.
- `config/discord_sso_config.php` reads Discord credentials from environment variables.
- `logout.php` now logs out to `home.php`.
- `home.php` CTA language now points users toward Discord login.
- `styles.css` adds small SSO login styling.
- `database/migrations/2026-05-05_discord_sso_users.sql` adds Discord identity columns.

## Discord Developer Portal settings

Create a Discord application and add this OAuth2 redirect URL:

```text
https://mb-future.musicball.net/integrations/discord/callback.php
```

For production, use:

```text
https://musicball.net/integrations/discord/callback.php
```

## Server environment variables

Set these on the server, not in GitHub:

```text
DISCORD_CLIENT_ID=your_discord_client_id
DISCORD_CLIENT_SECRET=your_discord_client_secret
DISCORD_REDIRECT_URI=https://mb-future.musicball.net/integrations/discord/callback.php
```

Optional, if you want to restrict login to one Discord server:

```text
DISCORD_ALLOWED_GUILD_ID=your_discord_server_id
```

If `DISCORD_ALLOWED_GUILD_ID` is set, the login file automatically adds the `guilds` OAuth scope and the callback verifies that the Discord user belongs to that server.

## Database migration

Run:

```sql
source database/migrations/2026-05-05_discord_sso_users.sql;
```

The migration is additive. It does not drop `Passcode` yet.

## How user matching works

1. If a Musicball user already has `DiscordUserID`, login matches that.
2. Otherwise, Musicball matches the Discord email to `ML_Users.Email`.
3. On first successful email match, Musicball stores the Discord identity on that user row.
4. If no match is found, login is denied with a commissioner-facing message.

## Important

Discord may not return an email for every account. For launch, make sure each current Musicball player has the same email in `ML_Users.Email` that they use for Discord, or manually set `DiscordUserID` after first testing.
