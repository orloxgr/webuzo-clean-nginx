# Native bridge installation and removal

## Hosting administrator

Review the source before installing a plugin that executes inside the existing privileged Webuzo backend. Build with `python scripts/build.py`; verify the ZIP checksums.

Upload the Webuzo ZIP in **Admin → Plugins → Installed Plugins → Add New**, then activate it. The native lifecycle hooks call a fixed Python command with no request-supplied parameters. Open **Nginx Cache** to check enrollment status; verify connectivity from WordPress as well.

Activation writes only the plugin's configuration/state/credentials below. It enrolls existing cache accounts after checking their Unix identities, private primary groups, native Webuzo `info` metadata and cache directory. Accounts without supported metadata, isolated groups or a safe cache directory are skipped. The plugin does not enable caching.

Activation also installs a fixed root cron that runs at minutes 0, 10, 20, 30, 40 and 50. Only native accounts with enabled cache/proxy are eligible. Refresh preserves valid keys and nonce/cooldown state, adds newly eligible accounts and removes stale enrollment. New accounts normally become available at the next successful scheduled run.

| Path | Owner / permissions | Purpose |
| --- | --- | --- |
| `/usr/local/webuzo/plugins/webuzo-clean-nginx/` | root, normal plugin directories/files | Native plugin bundle installed by Webuzo |
| `/etc/webuzo-clean-nginx/` | root:root 0700 | Server registry directory |
| `/etc/webuzo-clean-nginx/bridge.json` | root:root 0600 | Credential IDs, secrets and approved identities |
| `/var/lib/webuzo-clean-nginx/` | root:root 0755 | Own state root |
| `clients/` under that root | root:root 0711 | Traversable, not listable by accounts |
| `clients/UID.json` | root:account-primary-group 0440 | Account's scoped credential |
| `state/` | root:root 0700 | Nonces, cooldowns and lock files |
| `state/ID.json`, `state/slot-N` | root:root 0600 | Per-account state and two global job slots |
| `lifecycle.lock` | root:root 0600 | Serializes enrollment/removal against active bridge requests |
| `status.json` | root:root 0644 | State, account count, refresh interval and last-sync time |
| `/etc/cron.d/webuzo-clean-nginx` | root:root 0644 | Fixed enrollment command every ten minutes |
| `/var/lib/webuzo-clean-nginx/refresh.log` | root:root 0600 | Refresh changes and errors; no output for unchanged enrollment |

The panel must retain its existing loopback HTTP listener on port 2002. The bridge accepts only direct loopback requests with its own narrow HMAC authentication. It does not create a panel login session, accept an ordinary API key, or authenticate access to other actions.

The installer validates the plugin bundle and its ancestors. Native root:webuzo group-write permissions on `/usr/local/webuzo` are accepted only for a system group with no ordinary tenant members. The privileged panel's identity and native account metadata are trusted host components.

No Webuzo core, Nginx configuration, PHP configuration, account cache permissions, sudoers or other cron files are changed. No additional daemon or timer is installed. Existing Nginx/PHP/Webuzo/cron services are not restarted. The scheduler automatically reads the plugin's new cron file. Upgrading from the old filesystem candidate stops/disables only `webuzo-cache-purge.service` and removes its three known installed code/registry/unit files.

The refresh command is fixed: GNU `timeout` permits 45 seconds followed by a two-second kill grace period. An exclusive, nonblocking lifecycle lock prevents overlap with purges and other lifecycle operations. A busy invocation exits without changing enrollment and retries at the next ten-minute run. It never invokes Webuzo's cache-clear command or writes cache contents. Check the last-sync timestamp on the native status page and the private refresh log if new accounts remain unavailable. The host must have a working system cron; no packages are installed.

## WordPress administrator


Install and activate Clear Nginx for Webuzo from its WordPress ZIP. There are no connection credentials to paste or transport settings. Open **Tools → Clear Nginx for Webuzo**, test the connection, then clear cache. Every site sharing the hosting account is affected.

The PHP process must run under its account UID and be able to read its provisioned credential and connect to `127.0.0.1:2002`. Missing credentials, blocked sockets or a disabled companion plugin fail closed. There is no automatic API fallback.


## Replacing a development installation

1. Deactivate the WordPress development copy first. Remove that WordPress plugin through its Plugins screen if replacing a differently named folder.
2. In Webuzo Admin > Plugins, **deactivate the old Webuzo plugin before deleting its uploaded bundle**. This allows its existing removal hook to run. For the old service-based candidate, deactivation stops/disables `webuzo-cache-purge.service` and removes its known installed service/code/registry files. For the native bridge candidate, deactivation removes its enrollment cron and revokes its account credentials.
3. Upload `webuzo-clean-nginx-0.5.6-public-review.zip`, activate it, and check Nginx Cache for `ready`, eligible account count and the ten-minute enrollment schedule.
4. Install and activate `clear-nginx-for-webuzo-0.5.6-public-review.zip` in WordPress. Reload the dashboard to get forms using the new action/nonce identifiers, then test connection and clear cache.

If the separate old purge service was installed without a Webuzo plugin, or its known unit remains, activating the new Webuzo plugin also checks for `/etc/systemd/system/webuzo-cache-purge.service`. When present, it validates the three known old installed files as trusted root-owned regular files, runs `systemctl disable --now webuzo-cache-purge.service`, removes only `/usr/local/lib/webuzo-cache-purge/webuzo_cache_purge.py`, `/etc/webuzo-cache-purge/accounts.json` and that unit file when present, then runs `systemctl daemon-reload`. Unsafe files stop installation rather than broadening cleanup. It does not search for or remove arbitrary services, websites or cache contents. Other old empty directories/logs/socket leftovers are not recursively deleted.

These are administrator/root lifecycle operations. All paths created by the new activation are listed above. There is no need to manually remove account cache folders.

## Refresh, upgrade and remove

Adding accounts or enabling cache requires no plugin reactivation: the next successful cron reconciles enrollment. Deactivate/reactivate only when intentionally rotating all credentials or installing an upgrade. Deactivation first removes its own cron under the lifecycle lock, then revokes the registry and removes client credentials and account nonce files. Reactivation generates fresh keys, automatically read by WordPress. Runtime checks also compare Webuzo's UUID/creation metadata so old credentials fail after account recreation, even if the UID/name/GID are reused.

For an upgrade: deactivate, upload the reviewed replacement ZIP and activate. Confirm both the native status page and actual WordPress behavior. Revalidate after Webuzo upgrades because the native CLI and plugin loading sequence are external dependencies.

Deactivation never clears cache or removes websites. Own empty directories, the lifecycle lock, two slot files, private refresh log, disabled status and uploaded bundle remain. The disabled plugin no longer handles bridge requests. Remove only these exact plugin-owned leftovers if needed.

A previously queued refresh checks that both the registry and the plugin's cron still exist while holding the lifecycle lock. It exits without recreating a disabled installation. Stale identities fail closed while waiting for synchronization.
