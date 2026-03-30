# OpenDXP LinkedIn Bundle

This bundle synchronizes the latest LinkedIn (Company Page) posts into OpenDXP DataObjects (`LinkedinPost`).

## Requirements

- PHP 8.3
- Symfony 7.4
- OpenDXP 1.x

## Environment Configuration

Set the following in `.env` or as real environment variables:

- `LINKEDIN_CLIENT_ID`
- `LINKEDIN_CLIENT_SECRET`
- `LINKEDIN_REDIRECT_URI` (must match your OAuth app settings, e.g. `http://localhost/admin/linkedin/callback`)
- `LINKEDIN_ORGANIZATION_URN` (e.g. `urn:li:organization:XXXX`)
- `LINKEDIN_API_VERSION` (e.g. `202509`)
- Optional: `LINKEDIN_SCOPES` (default `r_organization_social`)

Tokens are stored in `WebsiteSetting` under the key `linkedin_token_{env}`.

## Bundle Configuration

Example (e.g. `config/packages/in_square_opendxp_linkedin.yaml`):

```yaml
in_square_opendxp_linkedin:
    object_folder: '/LinkedIn'
    assets_folder: '/linkedin'
    items_limit: 3
```

## OAuth

1. Open `GET /admin/linkedin/connect` and authorize access.
2. LinkedIn redirects to `GET /admin/linkedin/callback` — the token is stored in `WebsiteSetting`.

## Sync (cron/command)

Command:

```bash
bin/console app:linkedin:sync-latest --limit=3
```

Cron example (daily at 06:00):

```bash
0 6 * * * php /path/to/bin/console app:linkedin:sync-latest --limit=3
```

## Step-by-Step Installation

1. Install and register the bundle in the host project:
   - Ensure the bundle is registered in `config/bundles.php` as:
     `InSquare\OpendxpLinkedinBundle\InSquareOpendxpLinkedinBundle::class => ['all' => true]`
   - Ensure routes are loaded from `config/routes/in_square_opendxp_linkedin.yaml`.
2. Configure environment variables (e.g. in `.env`):
   - `LINKEDIN_CLIENT_ID`
   - `LINKEDIN_CLIENT_SECRET`
   - `LINKEDIN_REDIRECT_URI` (must match your OAuth app settings, e.g. `http://localhost/admin/linkedin/callback`)
   - `LINKEDIN_ORGANIZATION_URN` (e.g. `urn:li:organization:XXXX`)
   - `LINKEDIN_API_VERSION` (e.g. `202509`)
   - Optional: `LINKEDIN_SCOPES` (default `r_organization_social`)
3. Configure bundle settings in `config/packages/in_square_opendxp_linkedin.yaml`:
   - `object_folder` (default `/LinkedIn`)
   - `assets_folder` (default `/linkedin`)
   - `items_limit` (default `3`)
4. Install the bundle (creates the `LinkedinPost` class definition):
   ```bash
   bin/console opendxp:bundle:install InSquareOpendxpLinkedinBundle
   ```
5. Clear cache:
   ```bash
   bin/console cache:clear
   ```
6. Authorize LinkedIn (admin-only endpoints):
   - Open `GET /admin/linkedin/connect`
   - After successful login, LinkedIn redirects to `GET /admin/linkedin/callback`
7. Run sync manually:
   ```bash
   bin/console app:linkedin:sync-latest --limit=3
   ```
8. (Optional) Add cron (daily at 06:00):
   ```bash
   0 6 * * * php /path/to/bin/console app:linkedin:sync-latest --limit=3
   ```
