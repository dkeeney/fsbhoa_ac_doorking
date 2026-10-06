This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## About this plugin

`fsbhoa_ac_doorking` adds a hardware interface to the DoorKing 1838-010 Rev AB controller, connected through an RS-232/LAN adapter (testbed) or cellular adapter (production). It monitors vehicle entrance and exit for the HOA community.

It also adds management of DoorKing-related configuration by implementing WordPress hooks exposed by the `fsbhoa_ac_core` plugin.

## Configuration sync (what this release changes)

**Before:** controller configuration was entered and stored locally in DoorKing's RAM software. This covers the directory codes, the keypad gate codes, and the residents' windshield RFID codes. Both production controllers share that one RAM database.

**Now:** the Access Control system (WordPress/MySQL) is the source of truth for that configuration. RAM becomes a downstream consumer.

Flow when configuration changes:
1. The plugin (`class-fsbhoa-doorking-export.php`) writes the full configuration to `updates.csv` in its environment's folder on the NAS. It writes to a `.tmp` file first and then renames it, so readers never see a partial file. The folder is `To_RAM_Testbed\` or `To_RAM_Production\` under `Y:\Automation\DoorKing\` as seen from the RAM PC, and under `/mnt/shared/Automation/DoorKing/` as seen from the Pi (CIFS mount).
2. It then drops a trigger file, `import_now.flag`, in the same folder. The file contains `env=<environment>` and `host=<site host>`.
3. `ram_automation/DK_Sync.ahk` on the RAM PC checks for the trigger file every 3 seconds. When it appears and passes the environment check (below), the script clicks through RAM (File → Import → Import Now, with CSV format, Initialize and Multiple Accounts checked) to import `updates.csv`, replacing RAM's current configuration.
4. The script then clicks through Action → Send Data Now, selects the gate rows listed in its local `dk_sync.ini` (production: **North Gates** and **South Gates**), and sends the data to the controllers.
5. Cleanup: the script deletes `import_now.flag` and renames `updates.csv` to `updates_processed_<yyyyMMdd_HHmmss>.csv`. It logs to `Y:\Automation\DoorKing\Logs\automation_<environment>.log`.

Implications:
- Each export is a **full replacement**, not a delta. The CSV must contain every valid credential, and must stay within the 3000-card controller limit.
- The CSV must match the column layout RAM's importer expects. RAM's import is the format authority.
- **The file names are a contract** between the plugin and `DK_Sync.ahk` (`CsvFile` / `FlagFile`). The plugin's file names are the constants `Fsbhoa_DoorKing_Export::CSV_FILE_NAME` and `FLAG_FILE_NAME`; the full paths come from the `fsbhoa_dk_csv_path` and `fsbhoa_dk_lock_path` options, with defaults built from the environment. Change both sides together.

### RAM automation scripts (`ram_automation/`)

- `DK_Sync.ahk` — the AutoHotKey v2 watcher described above.
- `dk_sync.ini.example` — template for the RAM PC's local `C:\FSBHOA\dk_sync.ini`.
- `run.bat` — a manual test helper: `run.bat <testbed|production> <host>`. It clears that environment's log, renames an `updates*.csv` to `updates.csv`, and creates a trigger file with the given env and host.

This repo is the **master copy**. Edit the scripts here, then deploy them to `Y:\Automation\DoorKing\` on the RAM PC. Never edit the NAS copy directly. The scripts use CRLF line endings, which `.gitattributes` preserves.

The script drives RAM's GUI with hard-coded screen coordinates, so changes to RAM's UI or window size can break it. Never commit RAM exports or other resident data files (`export.csv`, `*DKEXPORT*.CSV`, `updates_processed_*.csv`).

## Environment separation

Testbed and production share the NAS, so a testbed test must never reach the production RAM or controllers, and vice versa.

- **`FSBHOA_AC_ENVIRONMENT`** is defined in each server's `wp-config.php` as `'testbed'` or `'production'`. It is deliberately not a WordPress option: it stays with the server, never with a database copy. It is meant for the whole Access Control system, not just this plugin.
- Other settings stay in the FSBHOA AC dashboard settings (WordPress options). This is safe because refreshing the testbed copies only the `ac_*` tables from production, never `wp_options`.
- Any code that could affect real hardware or outside systems must check the environment first and **fail closed**: do nothing if the constant is missing or unrecognized.

DoorKing RAM sync layers, all of which must agree before RAM is touched:
1. **Plugin:** the export refuses to run if `FSBHOA_AC_ENVIRONMENT` is missing, or if the CSV or trigger folder name doesn't contain the environment name. The DoorKing settings page shows the current environment.
2. **Separate folders:** `To_RAM_Testbed\` and `To_RAM_Production\`. The old shared `To_RAM\` is no longer used.
3. **Trigger contents:** the trigger file carries `env=` and `host=`.
4. **RAM PC:** each PC has a local `C:\FSBHOA\dk_sync.ini` (never on the NAS) naming its environment, the only host it accepts, and its gate rows. `DK_Sync.ahk` exits if the file is missing. It watches only its environment's folder, and renames any trigger file whose env or host doesn't match to `import_now.rejected_<timestamp>` without importing.

Vendor-code rotation (`notify_website_api` in `class-fsbhoa-doorking-rotation.php`):
- It refuses to post if `FSBHOA_AC_ENVIRONMENT` is missing.
- It sends `environment` and `source_host` in the payload.
- The receiver is the separate plugin `~/fsbhoa_ac_vender_code_receiver`, running on the website. It does not use these fields yet. **To do:** have the receiver store and display testbed and production codes separately, based on `environment`. The receiver isn't live in production yet.

## Hardware

- **Controller:** DoorKing 1838-010 Rev AB. Hard limit of **3000 cards** per controller — exports/sync must never exceed this.
- **Units:** three controllers — one on the testbed, two in production.
- **Testbed:** one card reader. The controller connects through a DoorKing 1830-188 RS-232-to-LAN adapter. A PC running DoorKing's RAM software talks to the controller via `testbed.fsbhoa.com` (Raspberry Pi 5), which hosts the testbed Access Control system.

### Testbed connection path

`[RAM PC] → [fsbhoa_doorking proxy on testbed.fsbhoa.com :8084] → [1830-188 adapter 192.168.1.50:10001] → [1838-010 controller]`

The Go proxy (`doorking/main.go`) relays RAM↔controller traffic transparently and reports card swipes to WordPress. Its config (`/var/lib/fsbhoa/doorking_proxy.json`) is generated by `class-fsbhoa-doorking-settings.php`.

### Production

One controller at each community entrance: **North Entrance** and **South Entrance**. Each controls an entrance lane with:
- a keypad
- a windshield RFID reader
- a vehicle arm
- a swinging or sliding gate

Production connection path: `[1838-010 controller] → [cellular adapter] → [DoorKing cloud] → [RAM PC at the office]`. Production traffic does not go through the testbed proxy.

### Design constraint: capture from the RAM side

The `fsbhoa_doorking` Go service **cannot** sit as a man-in-the-middle between a controller and RAM in production; that is only possible on the testbed. The current proxy design in `doorking/main.go` therefore does not work for production. The service must get its event data from what the RAM software sends, not by intercepting controller traffic. Don't build features that depend on the MITM path being present.

**Planned mechanism:** RAM's "Live Streaming" output, which streams events to a network address; the `fsbhoa_doorking` service listens there. **Status: not yet tested.** The stream's message format is unverified, so don't assume a format until it has been captured from a real RAM instance.

Develop and test against the testbed controller only; never send commands to the production controllers.

## To do before the production release

- **Migration script to set `FSBHOA_AC_ENVIRONMENT` on production.** Production's `wp-config.php` must get `define( 'FSBHOA_AC_ENVIRONMENT', 'production' );` as part of deployment, not by hand.
  - The constant is system-wide, so the script belongs with the deployment tooling (`~/deploy-production.sh` or `fsbhoa_ac_core`), not in this plugin.
  - It must be idempotent: add the constant only if it's missing, and never overwrite an existing value.
  - It must refuse to run on the testbed.
  - Until the constant is set, production DoorKing exports and vendor-code posts fail closed. That's safe, but they won't work.
- Add `fsbhoa_ac_doorking` to `~/deploy-production.sh`. It currently deploys only core, kiosk, zebra and uhppote.
- Set production's DoorKing settings to the `To_RAM_Production` paths.
- Give the office RAM PC its local `C:\FSBHOA\dk_sync.ini` (`Environment=production`, `GateRowsY=122,142`).
