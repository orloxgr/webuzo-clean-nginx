# Native bridge installation and removal

## Hosting administrator

Review the source before installing a plugin that executes inside the existing privileged Webuzo backend. Build with `python scripts/build.py`; verify the ZIP checksums.

Upload the Webuzo ZIP in **Admin → Plugins → Installed Plugins → Add New**, then activate it. The native lifecycle hooks call a fixed Python command with no request-supplied parameters. Open **Nginx Cache** to check enrollment status; verify connectivity from WordPress as well.

Activation writes only the plugin's configuration/state/credentials below. It enrolls existing cache accounts after checking their Unix identities, private primary groups, native Webuzo `info` metadata and cache directory. Accounts without supported metadata, isolated groups or a safe cache directory are skipped. The plugin does not enable caching.

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
| `status.json` | root:root 0644 | State and enrolled-account count only |

The panel must retain its existing loopback HTTP listener on port 2002. The bridge accepts only direct loopback requests with its own narrow HMAC authentication. It does not create a panel login session, accept an ordinary API key, or authenticate access to other actions.

The installer validates the plugin bundle and its ancestors. Native root:webuzo group-write permissions on `/usr/local/webuzo` are accepted only for a system group with no ordinary tenant members. The privileged panel's identity and native account metadata are trusted host components.

No Webuzo core, Nginx configuration, PHP configuration, account cache permissions, sudoers or cron files are changed. No additional daemon or timer is installed. Existing Nginx/PHP/Webuzo services are not restarted. Upgrading from the previous candidate stops/disables only `webuzo-cache-purge.service` and removes its three known installed code/registry/unit files.

## WordPress administrator

Install the companion WordPress ZIP. There are no connection credentials to paste or transport settings. Open **Tools → Webuzo Site Cache**, test the connection, then clear cache. Every site sharing the hosting account is affected.

The PHP process must run under its account UID and be able to read its provisioned credential and connect to `127.0.0.1:2002`. Missing credentials, blocked sockets or a disabled companion plugin fail closed. There is no automatic API fallback.

Earlier plugin API settings are removed on an administrator visit. If those settings held an account API key, revoke it in Webuzo separately; removing a saved copy does not invalidate that key or copies in old database backups.

## Refresh, upgrade and remove

Deactivate/reactivate to rebuild enrollment after adding accounts, changing identities/groups or enabling cache. Deactivation revokes the registry and removes client credentials and account nonce files. Reactivation generates fresh keys, automatically read by WordPress. Runtime checks also compare Webuzo's UUID/creation metadata so old credentials fail after account recreation, even if the UID/name/GID are reused.

For an upgrade: deactivate, upload the reviewed replacement ZIP and activate. Confirm both the native status page and actual WordPress behavior. Revalidate after Webuzo upgrades because the native CLI and plugin loading sequence are external dependencies.

Deactivation never clears cache or removes websites. Own empty directories, the lifecycle lock, two slot files, disabled status and uploaded bundle remain. The disabled plugin no longer handles bridge requests. Remove only these exact plugin-owned leftovers if needed.

There is no account-reconciliation cron. New eligible accounts remain unavailable until enrollment is refreshed; stale identities fail closed. Automatic reconciliation is separate future work.
