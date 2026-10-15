This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## About this plugin

`fsbhoa_ac_doorking` manages the configuration of the DoorKing 1838-010 Rev AB controllers at the vehicle entrances: the windshield tags, keypad gate codes and directory codes, which it sends to DoorKing's RAM software (below), and the vendor gate-code rotation. It implements WordPress hooks exposed by the `fsbhoa_ac_core` plugin. Live gate events come from RAM to `fsbhoa_ac_vehicle`, not to this plugin ("Live gate events: not this plugin" below).

## Configuration sync (what this release changes)

**Before:** controller configuration was entered and stored locally in DoorKing's RAM software. This covers the directory codes, the keypad gate codes, and the residents' windshield RFID codes. Both production controllers share that one RAM database.

**Now:** the Access Control system (WordPress/MySQL) is the source of truth for that configuration. RAM becomes a downstream consumer.

When RAM gets changes: the plugin doesn't export on every save. `class-fsbhoa-doorking-push.php` (`Fsbhoa_DoorKing_Push`) notices changes and reports them on core's red "pending changes" banner (`fsbhoa_push_targets`), and the export goes out when someone presses **Push Changes Now** (core fires `fsbhoa_push_to_controllers`). It also goes out at the nightly export (00:05 local), from **Resend Everything to DoorKing** on the settings page (`ajax_resend_all`: a full export even if RAM is up to date, refused while RAM is still importing; until 2026-10-14 "Generate RAM CSV Now"), and after a vendor-code rotation. Saving the DoorKing settings exports nothing (since 2026-10-14; before, every save exported and posted the vendor code to the website): a changed setting or Manual PIN shows on the banner like any other change.
- **How changes are noticed.** At the end of any request that may have changed what RAM holds (core's cardholder and vehicle hooks, `fsbhoa_pending_change_cardholder`, any `fsbhoa_dk_*` option), the plugin rebuilds the export in memory (`build_csv()`, about 0.2 s for 1,400 cards) and compares its md5 with the export RAM last confirmed. So any change is caught, whichever screen or plugin made it, and a save that changes nothing RAM holds isn't shown as pending. The hashes and the last result are in the option `fsbhoa_dk_push_state`.
- **A push** exports only if the md5 differs from the confirmed one, and not while RAM is still importing the previous export (up to 15 minutes).
- **The banner** shows "Changes waiting for RAM", then "Waiting for the RAM PC to pick up the export" (an error after 10 minutes: is `DK_Sync.ahk` running?), then "RAM is importing and sending to the gates" (an error after 15 minutes with no result), then green when `import_result.txt` (step 6) says success, or red with the script's message.

Flow of an export:
1. The plugin (`class-fsbhoa-doorking-export.php`) writes the full configuration to `updates.csv` in its environment's folder on the NAS. It writes to a `.tmp` file first and then renames it, so readers never see a partial file. The folder is `To_RAM_Testbed\` or `To_RAM_Production\` under `Y:\Automation\DoorKing\` as seen from the RAM PC, and under `/mnt/shared/Automation/DoorKing/` as seen from the Pi (CIFS mount).
2. It then drops a trigger file, `import_now.flag`, in the same folder. The file contains `env=<environment>`, `host=<site host>` and `time=<Unix time of the export>`.
3. `ram_automation/DK_Sync.ahk` on the RAM PC checks for the trigger file every 3 seconds. When it appears and passes the environment check (below), the script drives RAM (File → Import → Import Now, with CSV format, Initialize and Multiple Accounts checked) to import `updates.csv`, replacing RAM's current configuration. Menu commands are run by the items' names (`RunRamMenu()`), not by clicking screen positions; if RAM's wording ever changes, the error lists the names the menu has.
4. The script then clicks through Action → Send Data Now, presses Clear All, ticks the "Snd Now" box of **every** account in the list (production: **North Gates** and **South Gates**), and sends the data to the controllers. The list is an ActiveX grid (`VSFlexGrid8L1`) whose rows can't be read, so `TickAllAccounts()` goes by its pixels: it finds the rows as white bands along the grid's right edge, each row's checkbox as the first dark border left of that edge, clicks it, and checks every box has the tick's black pixels before pressing OK. If no row, no checkbox or an unticked box is found, the push fails. Every ticked account must then confirm the send (below), unless the optional `GateAccounts` in `dk_sync.ini` names the ones that must; that is only for a RAM that also lists an account with no reachable panel, such as a test account. It then waits for RAM to finish the send, which can take a minute and a half (OK at 05:57:25, "Download Data" at 05:58:49 on the workbench, 2026-10-14). RAM's status window can't be read (TODO #3), so the script reads the lines RAM adds after OK to its Live Streaming Disk File (`Live_<Environment>\stream.txt`, the file the vehicle plugin reads). It waits for one record per gate row whose message is "Download Data" (sent) or "Database is Up To Date" (nothing to send), from every ticked account, or only from the `GateAccounts` accounts when that is set (a record from another account is then logged and doesn't count). "Failed To Connect" is an error, and so is no such record within `SendTimeoutSeconds` (in `dk_sync.ini`, default 300). In those Raw Text records the type (`Network` or `RS232`) is at columns 45 to 57 and the message starts at column 66. Without a stream file the script falls back to watching the status window's Cancel Session button, which can end too early.
5. Cleanup: the script deletes `import_now.flag` and renames `updates.csv` to `updates_processed_<yyyyMMdd_HHmmss>.csv`, for inspecting the import. It first deletes any older `updates_processed_*.csv`, so only the newest is kept: each holds every resident's tags and gate codes, and the NAS copies the folder into its backups. It logs to `Y:\Automation\DoorKing\Logs\automation_<environment>.log`.
6. The script writes `import_result.txt` beside the flag, through a temp file and a rename (retried for 15 seconds, because the server reading the file can block the rename). Its lines are `finished=<local time>`, `trigger=<the flag's time=>`, `result=` `success`, `error` or `rejected` (the environment check failed), and `message=<one line>`. The plugin matches the result to its export by `trigger`, so an older import's result is never taken for the current one.

Implications:
- Each export is a **full replacement**, not a delta. The CSV must contain every valid credential, and must stay within the 3000-card controller limit.
- The CSV must match the column layout RAM's importer expects. RAM's import is the format authority.
- **The file names are a contract** between the plugin and `DK_Sync.ahk` (`CsvFile` / `FlagFile` / `ResultFile`), as are the flag's and the result's lines. The plugin's file names are the constants `Fsbhoa_DoorKing_Export::CSV_FILE_NAME` and `FLAG_FILE_NAME`, and `Fsbhoa_DoorKing_Push::RESULT_FILE`; the full paths come from the `fsbhoa_dk_csv_path` and `fsbhoa_dk_lock_path` options, with defaults built from the environment. Change both sides together.

### RAM automation scripts (`ram_automation/`)

- `DK_Sync.ahk` — the AutoHotKey v2 watcher described above. It also keeps RAM running for the `fsbhoa_ac_vehicle` plugin, when the RAM PC's `dk_sync.ini` sets `RamWatch=1`. The vehicle plugin reads RAM's live transactions from RAM's Live Streaming Disk File (`BaseDir\Live_<Environment>\stream.txt`), which RAM writes only while it runs. So every 60 seconds the script checks that RAM's window exists and starts RAM (`DoorKing32.exe`, its path noted while RAM runs or set as `RamPath`) if it doesn't, at most once every 10 minutes and never during an import. Each check also rewrites the heartbeat `ram_pc_status.txt` in that folder (`written`, `host`, `environment`, `ram_running`, as key=value lines, through a temp file and a rename), which the vehicle settings page shows. **That file name is a contract** with the vehicle plugin (`FSBHOA_Vehicle_Settings::RAM_PC_STATUS_FILE`); change both sides together. The script is the only thing that drives RAM's window, so imports and RAM starts share a `Busy` flag and never overlap. **Nothing restarts the script itself**, so each RAM PC must start it at logon (a shortcut in `shell:startup`, or a Task Scheduler task "At log on"). Without that, after a reboot neither imports nor RAM restarts happen: the heartbeat stops updating and the banner says the RAM PC has not picked up the export (seen on the testbed's KEENEY25, 2026-10-15). The two plugins don't depend on each other: the vehicle plugin relies only on this documented behavior, and works without it (it then can't show whether RAM is running). Until 2026-10-13 the script instead watched RAM's network stream (`StreamStatusUrl`) and restarted RAM when it dropped; the vehicle plugin's CLAUDE.md, "RAM stream (retired)", says why that went.
- `dk_sync.ini.example` — template for the RAM PC's local `C:\FSBHOA\dk_sync.ini`.
- `run.bat` — a manual test helper: `run.bat <testbed|production> <host>`. It clears that environment's log, renames an `updates*.csv` to `updates.csv`, and creates a trigger file with the given env and host.

This repo is the **master copy**. Edit the scripts here, then deploy them to `Y:\Automation\DoorKing\` on the RAM PC. Never edit the NAS copy directly. The scripts use CRLF line endings, which `.gitattributes` preserves.

The import drives RAM's GUI, with no screen coordinates since 2026-10-14: menu commands by the items' names (`RunRamMenu()`), dialog buttons and checkboxes by their controls (`Button2`, `FindButton()`), and only the Send Data Now account checkboxes by mouse clicks, at positions found from the grid's pixels (`TickAllAccounts()`). So a change to RAM's menu wording, dialog layout or the grid's look can still break it, and the error says what wasn't found. The RAM watch only starts RAM, with no clicks. Never commit RAM exports or other resident data files (`export.csv`, `*DKEXPORT*.CSV`, `updates_processed_*.csv`).

## Environment separation

The system-wide rules (`FSBHOA_AC_ENVIRONMENT`, fail closed) are in `ARCHITECTURE.md`. This plugin applies them as follows.

DoorKing RAM sync layers, all of which must agree before RAM is touched:
1. **Plugin:** the export refuses to run if `FSBHOA_AC_ENVIRONMENT` is missing, or if the CSV or trigger folder name doesn't contain the environment name. The DoorKing settings page shows the current environment.
2. **Separate folders:** `To_RAM_Testbed\` and `To_RAM_Production\`. The old shared `To_RAM\` is no longer used.
3. **Trigger contents:** the trigger file carries `env=` and `host=`.
4. **RAM PC:** each PC has a local `C:\FSBHOA\dk_sync.ini` (never on the NAS) naming its environment and the only host it accepts. `DK_Sync.ahk` exits if the file is missing. It watches only its environment's folder, and renames any trigger file whose env or host doesn't match to `import_now.rejected_<timestamp>` without importing.

Vendor-code rotation (`notify_website_api` in `class-fsbhoa-doorking-rotation.php`):
- It refuses to post if `FSBHOA_AC_ENVIRONMENT` is missing.
- It sends `environment` and `source_host` in the payload.
- A Manual PIN changed on the settings page is posted only after RAM confirms the push that carries it (`fsbhoa_dk_import_confirmed`, fired by `Fsbhoa_DoorKing_Push` when it reads a successful `import_result.txt`; the option `fsbhoa_dk_website_pending` holds when the change was made), so the website never shows a code the gates don't accept. The result is read when the banner polls, so this happens while an admin has a page open. The daily auto-rotation still posts as it runs.
- The receiver is the separate plugin `~/fsbhoa_ac_vender_code_receiver`, running on the website. It does not use these fields yet. **To do:** have the receiver store and display testbed and production codes separately, based on `environment`. The receiver isn't live in production yet.

## Hardware

- **Controller:** DoorKing 1838-010 Rev AB. Hard limit of **3000 cards** per controller — exports/sync must never exceed this.
- **Units:** three controllers — one on the testbed, two in production.
- **Testbed:** one card reader, on the developer's workbench. The workbench RAM PC talks to the controller directly through an RS-232-to-LAN adapter. Addresses are in `fsbhoa_ac_vehicle`'s CLAUDE.md ("Workbench").

### Testbed connection path

`[RAM PC] → [RS-232-to-LAN adapter] → [1838-010 controller]`

### Production

One controller at each community entrance: **North Entrance** and **South Entrance**. Each controls an entrance lane with:
- a keypad
- a windshield RFID reader
- a vehicle arm
- a swinging or sliding gate

Production connection path: `[1838-010 controller] → [cellular adapter] → [DoorKing cloud] → [RAM PC at the office]`.

### Live gate events: not this plugin

This plugin has no Go service and receives no live events. RAM's "Live Streaming" output goes straight to `fsbhoa_ac_vehicle`'s `vehicle_service`, which records tag and keypad reads in core's vehicle log (see that plugin's CLAUDE.md, "RAM Live Streaming format"). Windshield tags and entry codes are vehicle authentication, so they never go to the pedestrian log (`/monitor/log-event`).

**Retired 2026-10-13: the DoorKing proxy** (`fsbhoa_doorking`, `doorking/main.go`). It sat between RAM and the testbed controller on port 8084, read the controller's replies, and posted each tag read to the pedestrian log. It couldn't work in production, where controllers reach RAM through the DoorKing cloud, and RAM Live Streaming replaced it. Its service, settings (Proxy Listen Port, Physical Gate IP & Port, Controller Name) and `doorking_proxy.json` were removed. Its three options `fsbhoa_dk_proxy_port`, `fsbhoa_dk_gate_ip` and `fsbhoa_dk_controller_name` were deleted from the testbed's `wp_options` on 2026-10-14; nothing reads them. Production may still have them: delete them at deployment (`wp option delete fsbhoa_dk_proxy_port fsbhoa_dk_gate_ip fsbhoa_dk_controller_name`).

Develop and test against the testbed controller only; never send commands to the production controllers.

## To do before the production release

- `FSBHOA_AC_ENVIRONMENT` must be set on production first. The migration script for that is tracked in `ARCHITECTURE.md`.
- Add `fsbhoa_ac_doorking` to `~/deploy-production.sh`. It currently deploys only core, kiosk, zebra and uhppote.
- Set production's DoorKing settings to the `To_RAM_Production` paths.
- Give the office RAM PC its local `C:\FSBHOA\dk_sync.ini` (`Environment=production`, `ExpectedHost`, `BaseDir`; no `GateAccounts`). RAM there should list only North Gates and South Gates, since every listed account is sent to and must confirm. Set `DK_Sync.ahk` to start at logon there (see `DK_Sync.ahk` above), and check it comes back after a reboot.
- **Check the first production push reaches both controllers.** It couldn't be tested on the testbed, which has one. North Gates and South Gates are both sent the same (shared) data, in parallel. RAM's status list can't be read (TODO #3), so the script counts completion records in `Live_Production\stream.txt` instead: it ticks both accounts (the log should show "Ticked 2 account(s)") and waits for a "Download Data" or "Database is Up To Date" record from each, and fails on "Failed To Connect" from either. So **both accounts need the Disk File destination** in RAM's Live Streaming settings (`fsbhoa_ac_vehicle`'s CLAUDE.md, "DoorKing records: from RAM's Disk File"). An account without it makes every push time out with an error. The log should show two "RAM reports for ..." lines, one per account, before "SUCCESS". If production reaches the panels more slowly (by cellular, through DoorKing's cloud), raise `SendTimeoutSeconds`.
