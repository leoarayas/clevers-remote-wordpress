# Contributing to Clevers Remote WordPress Agent

## Plugin Status

**Current Status:** Active — Initial release (v1.0.0)

**Purpose:** Connects a WordPress site to Clevers Remote for controlled remote operations and telemetry.

**Distribution:** Private distribution for managed environments (not WordPress.org).

## Plugin Structure

The plugin follows WordPress coding standards:

```
clevers-remote-wordpress/
├── clevers-remote.php          # Main plugin file
├── includes/                   # Plugin classes
│   ├── class-remote-admin.php  # Admin interface
│   ├── class-remote-client.php # API client
│   ├── class-remote-reporter.php # Telemetry reporter
│   └── class-remote-scheduler.php # Cron scheduler
├── readme.txt                  # WordPress.org-style readme
├── .distignore                 # Distribution exclusions
├── .gitattributes              # Git attributes
└── .gitignore                  # Git exclusions
```

## WordPress Standards

The plugin follows WordPress coding standards:

- **Main file:** `clevers-remote.php` with plugin headers
- **Text domain:** `clevers-remote`
- **Prefix:** `clevers_remote_` for functions, `CLEVERS_REMOTE_` for constants
- **ABSPATH check:** All files check for `ABSPATH` at the top
- **Nonce verification:** REST API uses permission callbacks
- **Data sanitization:** Input is sanitized before use
- **Output escaping:** Output is escaped before display

## Capabilities

The plugin has these capabilities (configurable in Settings > Clevers Remote):

- `remote_allow_read` — Read inventory and site data
- `remote_allow_plugin_toggle` — Activate/deactivate plugins
- `remote_allow_plugin_updates` — Update plugins
- `remote_allow_theme_updates` — Update themes
- `remote_allow_core_updates` — Update WordPress core
- `remote_allow_cron` — Run WordPress cron
- `remote_allow_report_actions` — Report actions to remote

## REST API

The plugin exposes one REST endpoint:

- `GET /wp-json/clevers-remote/v1/status` — Returns plugin status, capabilities, and remote state

## Commit Conventions

This repository uses [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) format.

### Format

```
<type>(<scope>): <description>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `chore`: Maintenance tasks
- `refactor`: Code restructuring

### Examples

```
feat: add command audit logging
fix: correct heartbeat scheduling
docs: update readme with capabilities
chore: update dependencies
```

## Development

### Local Setup

1. Clone the repo
2. Copy to `wp-content/plugins/clevers-remote`
3. Activate in WordPress admin
4. Configure in Settings > Clevers Remote

### Testing

- Manual testing in WordPress environment
- Check REST API: `curl -X GET /wp-json/clevers-remote/v1/status`
- Verify admin pages load correctly
- Check cron jobs are scheduled

## Security

- **Never commit API keys** — Use WordPress options
- **Nonce verification** — All forms use nonces
- **Permission checks** — All actions check capabilities
- **Input sanitization** — All input is sanitized
- **Output escaping** — All output is escaped

## Changelog

### 1.0.0
- Initial release
- Remote status, registration, heartbeat, polling, and command audit flows
- Separate from monitoring-only agent
