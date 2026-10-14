# FSBHOA Access Control: DoorKing

An extension plugin for the [FSBHOA Access Control system](https://github.com/dkeeney/fsbhoa_ac_core). It manages the configuration of the DoorKing 1838 controllers at the community's vehicle entrances: residents' windshield RFID tags, keypad gate codes, directory codes, and a monthly vendor gate code. The access control system is the source of truth for all of it; the plugin sends it to DoorKing's RAM software, which sends it to the gates.

For what the whole system does, how it is put together, and how the plugins relate to each other, start with the core plugin: **[fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core)**. This plugin requires core and does nothing without it.

## What it does

- **DoorKing credentials on the cardholder and vehicle pages.** Each household gets a 4-digit keypad gate code, a 4-digit directory code and a directory opt-in (whether the resident's phone number is listed in the visitor call box). Windshield tags (5 digits) are entered per vehicle. New codes are generated at random, avoiding codes in use and easy ones such as `1234` or `0000`. Members of one household share its codes, and merging, restoring or moving a cardholder between households keeps them consistent. Core's cardholder list shows a DoorKing status icon.
- **Sync to RAM.** RAM (DoorKing's Remote Account Manager, a Windows program) used to be where this configuration was entered. Now the plugin builds RAM's import file from the database, and a script on the RAM PC imports it and sends it to the controllers. See [How the sync works](#how-the-sync-works).
- **"Push Changes Now."** When something RAM holds changes, core's red banner shows that DoorKing has changes waiting. Pressing **Push Changes Now** exports them, and the banner follows the import on the RAM PC until RAM confirms the gates have the data, or shows the error.
- **Vendor gate code rotation.** Vendors share one gate code that changes on the 1st of each month. The plugin picks next month's code mid-month, lets it open the gates a few days before the 1st, switches to it on the 1st, keeps the previous code working for a few grace days, and posts the codes to the HOA's public website for vendors to look up.
- **One-time backfill.** **DoorKing Backfill** imports an export from RAM to fill in the codes and tags of existing residents and vendors, matching RAM's names to cardholders (with a dry run). It was used to move from RAM to this system.

Live gate events (tag reads, keypad entries) are not this plugin's job: RAM writes them to its Live Streaming file, and the `fsbhoa_ac_vehicle` plugin records them.

## Hardware

- **Controllers:** DoorKing 1838-010 Rev AB, each with a keypad, a windshield RFID reader, a vehicle arm and a gate. Production has one at each entrance (North and South); the testbed has one on a workbench. Each controller holds at most **3000 cards**, and the export refuses to go over that.
- **RAM PC:** a Windows PC running DoorKing's RAM software and the sync script. In production RAM reaches the controllers through a cellular adapter and DoorKing's cloud; on the testbed, through an RS-232-to-LAN adapter.
- **A shared folder** both the access control server and the RAM PC can reach (a NAS share, mounted on the server and mapped as a drive on the PC).

## How the sync works

```
WordPress ──writes──> NAS: To_RAM_<Env>\updates.csv + import_now.flag
                              │
              RAM PC: DK_Sync.ahk sees the flag (every 3 s)
                              │
              RAM: File → Import (replaces RAM's configuration)
                   Action → Send Data Now (every account)
                              │
              controllers ──> RAM's Live Streaming file: "Download Data"
                              │
              DK_Sync.ahk writes import_result.txt ──> WordPress banner turns green
```

1. **Noticing changes.** At the end of any request that could change what RAM holds (a cardholder or vehicle saved, a DoorKing setting changed), the plugin builds the export in memory and compares its hash with the export RAM last confirmed. Only a real difference shows on the banner.
2. **Exporting.** On **Push Changes Now**, at the nightly export (00:05), from **Generate RAM CSV Now** on the settings page, or after a vendor code rotation, the plugin writes the whole configuration to `updates.csv`. Each export is a full replacement, not a list of changes: every active resident household and vendor, with their active, unexpired credentials. The file is written under a temporary name and renamed, so the script never reads half a file. Then the plugin drops a trigger file, `import_now.flag`, with the environment, the server's host name and the time.
3. **Importing.** `ram_automation/DK_Sync.ahk`, an AutoHotkey v2 script on the RAM PC, sees the trigger and checks that its environment and host match the PC's own configuration. It then drives RAM's menus by name: it imports the file (replacing RAM's configuration), opens Send Data Now, ticks every account and sends.
4. **Confirming.** The script waits for each gate's "Download Data" (or "Database is Up To Date") record in RAM's Live Streaming file, and treats "Failed To Connect" or no answer within the time limit as an error.
5. **Reporting.** The script writes `import_result.txt` (success, error or rejected, with a message, and the trigger's time so the plugin matches it to the right export), keeps the processed CSV for inspection (only the newest, since it holds every resident's codes), and logs to `Logs\automation_<env>.log`.

The script can also keep RAM running for the vehicle plugin (`RamWatch=1`): it starts RAM if its window is gone, and writes a heartbeat file the vehicle settings page shows.

The details, including the file contracts between the plugin and the script, are in `CLAUDE.md`, "Configuration sync".

### Export format

RAM's import format is the authority. Each household is one RAM record with a 15-character name (last name, first name), the phone number (the area code is left out when it is the local one), directory code, gate code, security level and up to 26 devices (windshield tags, plus any extra gate codes). A household with more devices continues in further records with numbered names. Vendors are exported the same way, without directory codes. Lines end in CRLF, as RAM expects.

## Testbed and production

The testbed and production share the NAS, so a testbed export must never reach production's RAM PC, or the reverse. Four layers must all agree before RAM is touched:

1. **The plugin** refuses to export if `FSBHOA_AC_ENVIRONMENT` is not defined in `wp-config.php`, or if the export folder's name doesn't contain the environment's name.
2. **Separate folders:** `To_RAM_Testbed` and `To_RAM_Production`.
3. **The trigger file** says which environment and host it came from.
4. **The RAM PC** has its own local `C:\FSBHOA\dk_sync.ini` (never on the NAS) naming its environment and the one server it accepts. The script watches only its own environment's folder, and renames a trigger with the wrong environment or host to `import_now.rejected_<time>` without importing.

The vendor code rotation also refuses to post without `FSBHOA_AC_ENVIRONMENT`, and tells the website which environment and host the codes came from.

## Vendor code rotation

When **Enable rotation** is on, a daily job at 00:01 (just before the nightly export):

- from the 15th, picks next month's code if there isn't one yet;
- for the last few days of the month (**Days before**; 2 means the last two days), puts that code in the gates as well, through a third vendor record, "Community Vendor (Next)", so both codes work;
- on the 1st, makes it the current code ("Community Vendor (Current)"), turns the Next record off, and moves the old code to "Community Vendor (Previous)", which keeps working through the grace days set in **Days after**;
- posts the current, previous and next codes to the website receiver (the separate `fsbhoa_ac_vender_code_receiver` plugin) with a bearer token, so the website can show vendors the right code;
- exports to RAM when a code changed.

Codes picked for residents' keypads avoid next month's vendor code, since two holders of one gate code can't both be in RAM.

## Requirements

- WordPress with the [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core) plugin active.
- `FSBHOA_AC_ENVIRONMENT` defined in `wp-config.php` as `'testbed'` or `'production'`.
- A shared folder, writable by the web server, that the RAM PC can also reach. The defaults are under `/mnt/shared/Automation/DoorKing/` on the server and `Y:\Automation\DoorKing\` on the RAM PC.
- On the RAM PC: Windows, DoorKing RAM, and [AutoHotkey v2](https://www.autohotkey.com/). In RAM's Live Streaming settings, every gate account needs the Disk File destination (`Live_<Env>\stream.txt`), which the script reads to confirm each send.
- A server crontab entry that requests `wp-cron.php` each night (see core's `ARCHITECTURE.md`), since WP-Cron runs only when the site gets a request.

## Installation

1. Put the repository in WordPress's plugin folder, or symlink it there, then activate **FSBHOA Access Control - DoorKing**:
   ```bash
   ln -s /home/pi/fsbhoa_ac_doorking /var/www/html/wp-content/plugins/fsbhoa_ac_doorking
   ```
2. On the RAM PC:
   - Copy `ram_automation/DK_Sync.ahk` to the shared folder (`Y:\Automation\DoorKing\`). This repository is the master copy: edit the script here and copy it over, never edit the copy on the NAS.
   - Copy `ram_automation/dk_sync.ini.example` to `C:\FSBHOA\dk_sync.ini` and set `Environment`, `ExpectedHost` and `BaseDir`. The example file explains the optional keys (`GateAccounts`, `RamWatch`, `RamPath`, `SendTimeoutSeconds`).
   - Run the script, and have Windows start it at login (for example, a shortcut in the Startup folder). It exits with a message if its configuration is missing.
3. Fill in the settings below, then press **Generate RAM CSV Now** and watch the script's log for the import and the send.

`ram_automation/run.bat <testbed|production> <host>` creates a trigger by hand for testing on the RAM PC.

## Configuration

**FSBHOA AC → DoorKing Service**, which also shows the server's environment:

| Setting | What it is |
|---|---|
| DoorKing RAM Version | The installed RAM version |
| DoorKing Account Name | The account name in RAM that the export's records belong to |
| CSV Export Path, Lock/Trigger File Path | Where the export and the trigger are written. The defaults come from the environment; both must be in that environment's folder. |
| Default Security Level | RAM's security level (time zone) for every exported record |
| Local Area Code | Phone numbers in this area code are exported without one, so the call box doesn't dial 1+ |
| Enable rotation, Days before, Days after | The vendor code rotation (above) |
| Website API URL, API token | Where the vendor codes are posted, and the token the website expects |

The buttons on the page save the settings, export to RAM now, and run the rotation now.

## Limitations

- RAM has no API, so the script drives its window. Menus are run by their names, but the account list in Send Data Now is found by its pixels, so a change in RAM's look can break the import. The push then fails, and the banner shows the script's error.
- Every export replaces RAM's whole configuration, so anything entered in RAM by hand is lost at the next export. Make changes in this system.
- A credential past its expiry date drops out at the next export, at the latest the nightly one.

## Documentation

- `CLAUDE.md`: how the plugin and the RAM script work, in detail, and the to-do list before the production release
- `TODO.md`: known issues and their history
- Core's `other_docs/ARCHITECTURE.md`: the system's architecture and shared conventions

## Author

* **David Keeney** - *Initial Design & Development* - <dkeeney@gmail.com>
