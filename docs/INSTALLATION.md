# Administrator installation and removal

This review candidate needs acceptance testing before production use. Installation requires the hosting administrator; WordPress users do not need SSH or root after their account is enrolled.

## Review the service boundary

The broker authenticates the actual Unix UID supplied by Linux. All websites running as that UID can clear all cache in the hosting account. There is no domain-level authorization. Callers cannot select a different account, path or command. Only `status`, `preview` and `purge` exist.

The fixed cache base is `/var/webuzo-data/nginx_proxy_cache`. The supported format is Nginx v5 x86-64 with `levels=1:2`. Other layouts/formats are skipped. Scans do not remove directories. Symlinks, hardlinks, unknown entries and other devices are skipped. Concurrent filesystem activity is not an atomic transaction; Nginx can rebuild cache during a scan.

Account enrollment stores UID, name and primary GID. Remove enrollment when deleting a hosting account. Recreation with the same UID/name/GID cannot be distinguished from the old identity. New accounts are not automatically enrolled.

## Files, permissions and service actions

| Path | Owner and mode | Purpose |
| --- | --- | --- |
| `/usr/local/lib/webuzo-cache-purge/` | root:root 0755 when created | Code directory |
| `/usr/local/lib/webuzo-cache-purge/webuzo_cache_purge.py` | root:root 0644 | Broker, invoked by Python |
| `/etc/webuzo-cache-purge/` | root:root 0700 when created | Configuration directory |
| `/etc/webuzo-cache-purge/accounts.json` | root:root 0600 | Approved UID/account/GID registry, no secrets |
| `/etc/systemd/system/webuzo-cache-purge.service` | root:root 0644 | Unit definition |
| `/run/webuzo-cache-purge/` | root:root 0755, runtime | Created by systemd |
| `/run/webuzo-cache-purge/control.sock` | root:root 0666, runtime | Local socket with kernel UID authorization |

Socket mode 0666 permits local connections; it does not grant purge authority. The root-owned parent prevents tenants replacing the socket. Unenrolled callers are rejected before their request is read. The WordPress client also checks the root ownership of the runtime directory and socket.

The unit runs as root to reach Nginx-owned cache without changing its permissions. It bounds capabilities to `CAP_DAC_OVERRIDE` and `CAP_DAC_READ_SEARCH`, uses `ProtectSystem=strict`, `ProtectHome=yes`, private temporary storage/devices, AF_UNIX-only sockets and syscall restrictions. Writable exceptions cover the cache base and this service's runtime directory. Limits: 128 MB memory, 25% of one CPU and 32 tasks. Review the unit itself for the complete settings.

The service logs account/UID/operation/counters to the systemd journal under its existing retention policy. It does not log cache contents or API keys. Python runs with `-I -B`. Enabling the unit creates the normal boot-target symlink.

## Prepare and inspect

Check that no older installation exists. If it does, back up its code, registry and unit and review an upgrade plan first: the installer replaces files and the **entire account list**, without backup or merging. Each file replacement is atomic; the overall multi-file installation is not. Partial failure may need manual removal or restoration.

Build with `python scripts/build.py`. Transfer the service bundle to a root-owned review directory outside document roots, for example `/root/webuzo-cache-purge-review`, mode 0700. Verify its SHA256 against the build output, inspect the archive members before extraction, then inspect the code and unit. Never execute an installer or source still writable by a hosting tenant.

From the reviewed directory, using a real account name in place of the example:

```sh
python3 -I -B server/install.py --account example_account
```

This is plan-only: no directory/file/service changes. It validates the account and existing cache directory and prints the UID/GID mapping, destinations, modes and service commands. Accounts must have UID ≥1000. Confirm all output before applying.

## Apply only the reviewed plan

As root:

```sh
python3 -I -B server/install.py --account example_account --apply
```

The installer verifies Python/systemd tools, runs `systemd-analyze verify`, checks destination ancestors are root-owned and not tenant-writable or symlinks, creates its own missing directories, and writes the three files listed above. It does not chmod existing directories or install system packages. It then runs:

```sh
systemctl daemon-reload
systemctl enable webuzo-cache-purge.service
systemctl restart webuzo-cache-purge.service
systemctl is-active --quiet webuzo-cache-purge.service
```

No Nginx, Apache, PHP-FPM or Webuzo restart occurs. The installer does not change their configuration, cache ownership/permissions, sudoers, cron, firewall, SSH or API keys. Starting the service does not scan or clear cache.

For selected accounts, repeat `--account NAME`. After a successful pilot, `--all-webuzo-accounts` discovers existing eligible Unix accounts with cache directories. Review plan-only output first; add `--apply` separately. Each run replaces the registry with exactly that selection. The service reloads it on restart. Future accounts require a new enrollment plan.

## Acceptance checks

1. Check `systemctl status webuzo-cache-purge.service`, `journalctl -u webuzo-cache-purge.service`, ownership/modes, sandbox behavior and absence of a TCP listener.
2. Install the WordPress plugin, choose Local cache service and Test connection from the actual PHP-FPM runtime. If PHP restrictions block the socket, investigate and review a specific configuration change before making it. The installer does not relax PHP restrictions.
3. Preview cache and inspect counters. Investigate errors or unsupported formats before proceeding. Wait 10 seconds before another scan.
4. Arrange an authorized account-wide pilot purge. Confirm a previously cached anonymous page goes HIT → MISS, then returns to HIT. Account cache loss can temporarily increase backend load.
5. Use two distinct real hosting UIDs to test isolation, and an unenrolled UID to check rejection. Two domains sharing one UID do not prove account isolation.

Automated integration uses disposable account directories under one test UID. It does not establish the privileged production boundary or isolation between two real callers. The broker has not had an independent security audit.

Limits include 100,000 entries or 15 seconds per scan, one scan per UID, 10-second cooldown, 8 workers and 512-byte requests with a 2-second read deadline. Incomplete scans are reported. Repeating scans over very large caches might revisit the same unsupported prefix. A compromised enrolled tenant can repeatedly purge its own account within these limits; no absolute DoS guarantee is offered.

## WordPress credentials and compatibility

Local mode sends no hosting key, username or panel URL. No automatic API fallback occurs. Existing encrypted API settings remain until removed but are unused locally. Removing a stored key does not revoke it in Webuzo; revoke obsolete keys separately. Explicit API compatibility uses broader account credentials and has different risks, described in the README.

## Remove the service

Disable the WordPress plugin or change its connection method first. As root, verify the exact paths before removing only this installation:

```sh
systemctl disable --now webuzo-cache-purge.service
rm -- /etc/systemd/system/webuzo-cache-purge.service
rm -- /usr/local/lib/webuzo-cache-purge/webuzo_cache_purge.py
rm -- /etc/webuzo-cache-purge/accounts.json
rmdir -- /usr/local/lib/webuzo-cache-purge
rmdir -- /etc/webuzo-cache-purge
systemctl daemon-reload
systemctl reset-failed webuzo-cache-purge.service
```

Systemd normally removes its runtime directory on stop. If something remains, inspect it and remove only this service's socket and empty runtime directory. Use no recursive deletion. `rmdir` deliberately fails if unexpected files remain; inspect them. `reset-failed` may report the removed unit no longer exists. Removal does not delete site files, cache directories or Webuzo configuration. Previously purged cache is rebuilt through visits, not restored by uninstalling.
