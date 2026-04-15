=== Clevers Remote Agent ===
Contributors: clevers
Tags: remote-management, maintenance, wordpress, operations
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short Description: Connects a WordPress site to Clevers Remote for controlled remote operations and telemetry.

== Description ==
Clevers Remote Agent is the operations-focused variant of the Clevers site agent line.

It can register a site with a remote backend, send heartbeat and telemetry data, poll for commands, and execute allowed maintenance actions such as reading inventory, running cron, or applying updates depending on the local policy.

This plugin is intended for managed environments and private distribution, not for WordPress.org-style monitoring-only use cases.

== Installation ==
1. Copy the `clevers-remote` folder into `wp-content/plugins/`.
2. Activate the plugin from the Plugins screen.
3. Go to Settings > Clevers Remote and configure the Agent Key, Endpoint URL, interval, and allowed capabilities.

== Frequently Asked Questions ==
= What can the agent do? =
It can report telemetry, register the site, send heartbeats, poll remote commands, and execute only the capabilities allowed in local settings.

= Is every remote action enabled by default? =
No. Sensitive actions should remain disabled unless your backend needs them and you are auditing command execution.

== Changelog ==
= 1.0.0 =
* Initial split of the remote-management plugin from the monitoring-only agent.
* Added separate remote status, registration, heartbeat, polling, and command audit flows.
