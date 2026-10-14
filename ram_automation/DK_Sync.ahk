#Requires AutoHotkey v2.0
#SingleInstance Force
Persistent

; --- LOCAL ENVIRONMENT CONFIG ---
; Each RAM PC has its own dk_sync.ini on its LOCAL disk (never on the NAS), e.g.:
;
;   [DK_Sync]
;   Environment=testbed
;   ExpectedHost=testbed.fsbhoa.com
;   BaseDir=Y:\Automation\DoorKing
;   GateRowsY=122
;
; See dk_sync.ini.example. IniRead does not strip inline comments, so keep values bare.
; The script refuses to start if the file or any key is missing, and rejects any
; trigger file whose env/host do not match. See CLAUDE.md "Environment separation".
global IniFile := "C:\FSBHOA\dk_sync.ini"

ReadRequired(key) {
    val := Trim(IniRead(IniFile, "DK_Sync", key, ""))
    if (val = "") {
        MsgBox("DK_Sync: '" . key . "' is missing from " . IniFile . ". The script will exit.", "DK_Sync", "Iconx")
        ExitApp
    }
    return val
}

if !FileExist(IniFile) {
    MsgBox("DK_Sync: local config " . IniFile . " not found. The script will exit.", "DK_Sync", "Iconx")
    ExitApp
}

global Environment  := StrLower(ReadRequired("Environment"))
global ExpectedHost := StrLower(ReadRequired("ExpectedHost"))
global BaseDir      := ReadRequired("BaseDir")
global GateRowsY    := StrSplit(ReadRequired("GateRowsY"), ",", " ")
; Optional: the accounts those rows are, in RAM's names. The rows are clicked by position (the
; list is an ActiveX grid that can't be read), so RAM's completion records are checked against
; these names: a click on the wrong row then fails the push instead of passing unnoticed.
global GateAccounts := Trim(IniRead(IniFile, "DK_Sync", "GateAccounts", ""))
GateAccounts := (GateAccounts = "") ? [] : StrSplit(GateAccounts, ",", " ")
if (GateAccounts.Length && GateAccounts.Length != GateRowsY.Length) {
    MsgBox("DK_Sync: GateAccounts lists " . GateAccounts.Length . " accounts but GateRowsY has " . GateRowsY.Length . " rows. The script will exit.", "DK_Sync", "Iconx")
    ExitApp
}

if (Environment != "testbed" && Environment != "production") {
    MsgBox("DK_Sync: Environment must be 'testbed' or 'production', not '" . Environment . "'. The script will exit.", "DK_Sync", "Iconx")
    ExitApp
}

; --- RAM WATCH AND HEARTBEAT (optional) ---
; The vehicle plugin reads RAM's live transactions from RAM's Live Streaming Disk File
; (BaseDir\Live_<Environment>\stream.txt), so RAM must be running. When dk_sync.ini sets
; RamWatch=1, the script checks every 60 seconds that RAM's window exists, and starts RAM if
; it doesn't (at most once every 10 minutes, and not during an import). Each check also
; rewrites the heartbeat file ram_pc_status.txt in that folder (written, host, environment,
; ram_running), which the vehicle settings page shows, so a quiet stream file can be told
; apart from a stopped RAM. The file name is a contract with the vehicle plugin. RAM
; restarts are rare now: swipes in the first seconds after RAM starts reach no output.
; See fsbhoa_ac_vehicle's CLAUDE.md, "DoorKing records: from RAM's Disk File".
global RamWatch       := Trim(IniRead(IniFile, "DK_Sync", "RamWatch", "0")) = "1"
global RamRetryMs     := 600000   ; at most one start every 10 minutes
global RamLastStart   := 0        ; A_TickCount of the last start; 0: none yet
global LiveDir        := BaseDir . "\Live_" . StrTitle(Environment)
global StatusFile     := LiveDir . "\ram_pc_status.txt"
; RAM's Live Streaming Disk File. Besides live transactions, RAM writes a status record there
; when a Send Data session ends, which is how the import knows the send is finished.
global StreamFile     := LiveDir . "\stream.txt"
global SendTimeoutMs  := 1000 * Integer("0" . Trim(IniRead(IniFile, "DK_Sync", "SendTimeoutSeconds", "300")))
global StatusFileOk   := true     ; so a failing heartbeat write is logged once, not every check
; DoorKing32.exe's path, to start RAM: from dk_sync.ini (RamPath), else noted while RAM runs
global RamPath        := Trim(IniRead(IniFile, "DK_Sync", "RamPath", ""))

; Only one task drives RAM at a time: an import or starting RAM
global Busy := false

; --- CONFIGURATION ---
global WatchDir    := BaseDir . "\To_RAM_" . StrTitle(Environment)
global LogDir      := BaseDir . "\Logs"
global FlagFile    := WatchDir . "\import_now.flag"
global CsvFile     := WatchDir . "\updates.csv"
; The import's result, for the server's push status: a contract with fsbhoa_ac_doorking
; (Fsbhoa_DoorKing_Push::RESULT_FILE). trigger= echoes the trigger file's time=.
global ResultFile  := WatchDir . "\import_result.txt"
global TriggerTime := ""
global LogFile     := LogDir . "\automation_" . Environment . ".log"
global RamWinTitle := "ahk_class DoorKing32AppClass ahk_exe DoorKing32.exe"

; Ensure directories exist on startup
if !DirExist(WatchDir)
    DirCreate(WatchDir)
if !DirExist(LogDir)
    DirCreate(LogDir)

WriteLog("AHK Sync Script Started (" . Environment . ", accepting triggers from " . ExpectedHost . "). Watching " . WatchDir)
SetTimer(WatchFolder, 3000)

; Temporary: log RAM's main window controls, to see whether its account list can be read
; (to select the Send Data gate rows by account name instead of GateRowsY)
if WinExist(RamWinTitle)
    LogControls(RamWinTitle, "RAM main window")

if RamWatch {
    WriteLog("RAM watch on: heartbeat in " . StatusFile)
    CheckRam()
    SetTimer(CheckRam, 60000)
}

WatchFolder() {
    global Busy
    if !FileExist(FlagFile) || Busy
        return
    Busy := true
    try
        HandleTrigger()
    finally
        Busy := false
}

HandleTrigger() {
    SetTimer(WatchFolder, 0)

    ; --- ENVIRONMENT GUARD ---
    ; The trigger file must say it came from this PC's environment and expected host.
    ; A rejected trigger is renamed (not deleted) so it can be investigated, and the CSV is left alone.
    reason := CheckTrigger()
    if (reason != "") {
        WriteLog("REJECTED trigger: " . reason)
        FileMove(FlagFile, WatchDir . "\import_now.rejected_" . FormatTime(, "yyyyMMdd_HHmmss"), 1)
        WriteResult("rejected", reason)
        SetTimer(WatchFolder, 3000)
        return
    }
    
    if !FileExist(CsvFile) {
        WriteLog("WARNING: Flag file found, but " . CsvFile . " is missing. Aborting run.")
        FileDelete(FlagFile)
        WriteResult("error", "updates.csv is missing")
        SetTimer(WatchFolder, 3000)
        return
    }

    WriteLog("Trigger file and CSV detected. Starting import process.")

    result := "success", message := ""
    try {
        RunAutomation()
        WriteLog("SUCCESS: Full Import and Network Send sequence completed.")
    } catch Error as err {
        WriteLog("ERROR: " . err.Message)
        result := "error", message := err.Message
    }
    ; Minimize RAM after a failed run too (a successful one already has)
    if WinExist(RamWinTitle)
        WinMinimize(RamWinTitle)

    ; Cleanup
    if FileExist(FlagFile)
        FileDelete(FlagFile)
        
    ; Keep the processed CSV for inspecting this import, but only the newest one: each holds
    ; every resident's tags and gate codes, and the NAS copies this folder into its backups.
    if FileExist(CsvFile) {
        removed := 0
        Loop Files WatchDir . "\updates_processed_*.csv" {
            try {
                FileDelete(A_LoopFileFullPath)
                removed++
            }
        }
        if removed
            WriteLog("Deleted " . removed . " older updates_processed CSV file(s).")
        FileMove(CsvFile, WatchDir . "\updates_processed_" . FormatTime(, "yyyyMMdd_HHmmss") . ".csv", 1)
    }

    WriteResult(result, message)
    SetTimer(WatchFolder, 3000)
}

; Writes import_result.txt (key=value lines) through a temp file, so the server never reads
; half of it. result is success, error or rejected; message is one line.
WriteResult(result, message) {
    tmp := ResultFile . ".tmp"
    try {
        if FileExist(tmp)
            FileDelete(tmp)
        FileAppend("finished=" . FormatTime(, "yyyy-MM-dd HH:mm:ss") . "`r`ntrigger=" . TriggerTime
            . "`r`nresult=" . result . "`r`nmessage=" . RegExReplace(message, "[\r\n]+", " ") . "`r`n", tmp)
    } catch Error as err {
        WriteLog("Could not write " . tmp . " (" . err.Message . ").")
        return
    }
    ; The server reads the result file every few seconds while its banner waits, and its NAS
    ; client keeps the file open for a moment after each read, so replacing it can fail.
    ; Try for up to 15 seconds.
    lastErr := ""
    loop 30 {
        try {
            FileMove(tmp, ResultFile, 1)
            return
        } catch Error as err {
            lastErr := err.Message
        }
        Sleep(500)
    }
    WriteLog("Could not write " . ResultFile . " (" . lastErr . ").")
}

CheckTrigger() {
    global TriggerTime
    fields := Map()
    for line in StrSplit(FileRead(FlagFile), "`n", "`r") {
        parts := StrSplit(line, "=", " `t", 2)
        if (parts.Length = 2)
            fields[StrLower(parts[1])] := StrLower(parts[2])
    }
    env  := fields.Has("env")  ? fields["env"]  : ""
    host := fields.Has("host") ? fields["host"] : ""
    TriggerTime := fields.Has("time") ? fields["time"] : ""
    if (env != Environment)
        return "env '" . env . "' does not match this PC's '" . Environment . "'"
    if (host != ExpectedHost)
        return "host '" . host . "' does not match expected '" . ExpectedHost . "'"
    return ""
}

RunAutomation() {
    if !WinExist(RamWinTitle) {
        throw Error("DoorKing RAM is not running. Please launch it.")
    }
    ; --- WAKE UP AND MAXIMIZE ---
    ; Pull it up from the taskbar if it was minimized
    WriteLog("Waking up DoorKing and bringing to front...")
    
    ; Force it to show, restore from taskbar
    WinShow(RamWinTitle)
    Sleep(200)
    WinRestore(RamWinTitle)
    Sleep(500)
    WinSetAlwaysOnTop(1, RamWinTitle)   ; <--- turns it ON
    Sleep(200)
    WinActivate(RamWinTitle)
    
    ; If it fails to come to the front within 5 seconds, halt immediately!
    if !WinWaitActive(RamWinTitle, , 5) {
        throw Error("CRITICAL: Failed to wake up or activate DoorKing window.")
    }
    Sleep(500)
    
    ; Drop it back to normal behavior now that it is safely in front
    WinSetAlwaysOnTop(0, RamWinTitle)   ; <--- The '0' turns it OFF!
 
    CoordMode("Mouse", "Window")

    ; --- ESCAPE HATCH ---
    ; 1. Hunt down and close any stuck Yes/No warning dialogs
    while WinExist("ahk_class #32770 ahk_exe DoorKing32.exe") {
        WriteLog("WARNING: Found a stuck dialog box. Attempting to clear it.")
        WinActivate("ahk_class #32770 ahk_exe DoorKing32.exe")
        Sleep(200)
        Send("n") 
        Sleep(300)
        if WinExist("ahk_class #32770 ahk_exe DoorKing32.exe") {
            WinClose("ahk_class #32770 ahk_exe DoorKing32.exe")
            Sleep(300)
        }
    }
    Sleep(500)

    ; 2. Ensure main window has focus again, then clear any active text boxes
    WinActivate(RamWinTitle)
    Sleep(200)
    Send("{Esc 3}")
    Sleep(500)

    ; --- 3. RESET TO MAIN MENU ---
    WriteLog("Forcing UI to main menu (View -> Accounts)")
    RunRamMenu("View", "Accounts")
    Sleep(1500)
    
    Send("{Esc 2}")
    Sleep(500)

    ; --- 4. OPEN IMPORT WIZARD ---
    WriteLog("Opening File -> Import -> Import Now")
    
    RunRamMenu("File", "Import", "Import Now")
    Sleep(1500)
    
    if !WinWaitActive("Import", , 5)
        throw Error("Import dialog did not appear.")
        
    ; --- 5. SURGICAL UI CONTROL ---
    WriteLog("Configuring Import settings.")
    ControlSetText(CsvFile, "Edit1", "Import")
    Sleep(300)
    
    ControlSetChecked(1, "Button2", "Import") ; Force 'CSV Format'
    Sleep(100)
    ControlSetChecked(1, "Button5", "Import") ; Force 'Initialize'
    Sleep(100)
    ControlSetChecked(1, "Button7", "Import") ; Force 'Multiple Accounts'
    Sleep(300)
    
    ControlFocus("Button8", "Import")
    Sleep(2000)
    ControlSend("{Space}", "Button8", "Import")
    
    ; --- 6. SMART DIALOG HANDLER (THE "EYES") ---
    WriteLog("Processing Import dialogs...")
    
    importSuccessful := false
    timeoutTime := A_TickCount + 60000 ; 60 second max timeout
    
    while (A_TickCount < timeoutTime) {
        if WinWaitActive("ahk_class #32770", , 2) {
            Sleep(500) 
            
            dialogText := WinGetText("A")
            WriteLog("Intercepted dialog with text: " . dialogText)
            
            if InStr(dialogText, "overwritten") or InStr(dialogText, "Initialize") {
                WriteLog("Handling Overwrite Warning. Clicking Yes...")
                ControlFocus("Button1", "A") 
                Sleep(2000)
                ControlSend("{Space}", "Button1", "A")
                Sleep(1000)
                continue 
            }
            
            if InStr(dialogText, "successful") or InStr(dialogText, "account") {
                if InStr(dialogText, "0 successful") or InStr(dialogText, " 0 account") {
                    ControlSend("{Space}", "Button1", "A")
                    throw Error("CRITICAL FAILURE: 0 accounts were imported! Aborting network push.")
                }
                
                importSuccessful := true
                WriteLog("Import confirmed successful. Closing dialog.")
                ControlFocus("Button1", "A")
                Sleep(2000)
                ControlSend("{Space}", "Button1", "A")
                Sleep(1000)
                break 
            }
            
            if InStr(dialogText, "Error") or InStr(dialogText, "Invalid") {
                ControlSend("{Space}", "Button1", "A")
                throw Error("CRITICAL FAILURE: Import threw an error: " . dialogText)
            }
            
            WriteLog("Unknown dialog detected. Hitting OK just in case...")
            ControlFocus("Button1", "A")
            Sleep(2000)
            ControlSend("{Space}", "Button1", "A")
            Sleep(1000)
        }
        
        if (importSuccessful)
            break
    }
    
    if (!importSuccessful) {
        throw Error("CRITICAL FAILURE: Import timed out or failed. Aborting network push.")
    }

    ; --- 7. OPEN SEND DATA WIZARD ---
    WriteLog("Opening Action -> Send Data Now...")
    WinActivate(RamWinTitle)
    Sleep(500)
    
    RunRamMenu("Action", "Send Data Now")
    Sleep(500)

    ; --- 8. CONFIGURE SEND DATA ---
    if !WinWaitActive("ahk_class #32770", , 5)
        throw Error("Send Data dialog did not appear.")
    sendDlg := "ahk_id " . WinExist("A")
        
    WriteLog("Selecting gates for transmission.")
    
    ControlFocus("Button3", "A") ; Clear Now
    Sleep(200)
    ControlSend("{Space}", "Button3", "A")
    Sleep(500)
    
    ; Gate rows come from dk_sync.ini (production: North Gates 122, South Gates 142)
    for rowY in GateRowsY {
        WriteLog("Selecting gate row at y=" . rowY)
        MouseMove(310, Integer(rowY))
        Sleep(200)
        Click("Down")
        Sleep(100)
        Click("Up")
        Sleep(500)
    }
    
    ; Note where the Live Streaming file ends, so only records RAM writes after OK count
    streamStart := FileExist(StreamFile) ? FileGetSize(StreamFile) : -1

    WriteLog("Initiating transmission to gate controllers.")
    ControlFocus("Button1", "A") ; OK
    Sleep(500)
    ControlSend("{Space}", "Button1", "A")

    ; --- 9. WAIT FOR NETWORK SEND ---
    ; First the Send Data dialog closes. (Waiting for "any active dialog" here used to return
    ; at once, on the Send Data dialog itself, so RAM was minimized mid-send and the real
    ; completion dialog was left open.)
    ; If no gate row got selected (the click at GateRowsY missed), RAM answers OK with a
    ; message ("No account selected. You must choose at least one account to send data.")
    ; instead. Close it and the Send Data dialog, and fail with that.
    closeBy := A_TickCount + 30000
    while WinExist(sendDlg) {
        if (msg := WinExist("ahk_class #32770 ahk_exe DoorKing32.exe", "account selected")) {
            text := RegExReplace(WinGetText("ahk_id " . msg), "\s+", " ")
            WinClose("ahk_id " . msg)
            Sleep(500)
            try {
                ControlFocus("Button2", sendDlg)   ; Cancel
                Sleep(200)
                ControlSend("{Space}", "Button2", sendDlg)
            }
            Sleep(500)
            if WinExist(sendDlg)
                WinClose(sendDlg)
            throw Error("RAM refused to send: " . Trim(text) . " The click on the gate row (GateRowsY in dk_sync.ini) missed. Was someone using the RAM PC's mouse?")
        }
        if (A_TickCount >= closeBy)
            throw Error("Send Data dialog did not close after OK.")
        Sleep(250)
    }
    WriteLog("Data is transmitting to gates. Waiting for completion...")

    ; RAM prepares for up to a minute or more before it reaches the panel, and the status
    ; window doesn't show when the session ends. RAM's own record in the Live Streaming file
    ; does: one per account sent, when its session ends.
    if (streamStart >= 0) {
        WaitForSendRecords(streamStart)
        statusDlg := ""
        if (hwnd := WinExist("ahk_class #32770 ahk_exe DoorKing32.exe", "Cancel Session"))
            statusDlg := "ahk_id " . hwnd
    } else {
        WriteLog("No Live Streaming file at " . StreamFile . ", so the end of the send can't be confirmed; watching RAM's status window instead.")
        statusDlg := WaitForSessionButton()
    }
    if (statusDlg = "") {
        WriteLog("Automation successful. Minimizing DoorKing RAM.")
        Sleep(1000)
        WinMinimize(RamWinTitle)
        return
    }

    ; The status window's list can't be read (its rows hold no text), so the result comes
    ; from the Live Streaming records above. Close the window: its Close button may still be
    ; greyed out for a moment after the last record, and a mouse click on it doesn't take,
    ; so wait for it and press it like OK. WinClose if that fails.
    closeBtn := FindButton("Close", statusDlg)
    if (closeBtn != "") {
        enabledBy := A_TickCount + 15000
        while (A_TickCount < enabledBy && WinExist(statusDlg)) {
            try {
                if ControlGetEnabled(closeBtn, statusDlg)
                    break
            }
            Sleep(250)
        }
        try {
            ControlFocus(closeBtn, statusDlg)
            Sleep(200)
            ControlSend("{Space}", closeBtn, statusDlg)
        }
    }
    Sleep(1000)
    if WinExist(statusDlg) {
        WriteLog("Close button missed. Closing the status window.")
        WinClose(statusDlg)
        Sleep(500)
    }

    ; --- 10. CLEANUP & MINIMIZE ---
    WriteLog("Automation successful. Minimizing DoorKing RAM.")
    Sleep(1000) ; Give the UI a second to settle after closing the dialog
    WinMinimize(RamWinTitle)
}

; Waits until RAM has written, after the given byte of the Live Streaming file, a record
; that a Send Data session ended for each gate row: "Download Data" (sent) or "Database is
; Up To Date" (nothing to send). With GateAccounts set, the records must be from those
; accounts; a record from another account is logged and doesn't count. "Failed To Connect"
; for a gate account is an error, and so is no record within SendTimeoutSeconds. The file
; is in RAW Text: a status record has its account name in columns 1-23, its type (Network
; or RS232) at columns 45-57 and its message from column 66.
WaitForSendRecords(offset) {
    done := Map()       ; Map() keys ignore case only with CaseSense off
    done.CaseSense := false
    others := ""        ; accounts that reported but aren't in GateAccounts
    partial := ""
    deadline := A_TickCount + SendTimeoutMs
    loop {
        if (A_TickCount >= deadline) {
            missing := ""
            for name in GateAccounts
                if !done.Has(SubStr(name, 1, 23))
                    missing .= (missing = "" ? "" : ", ") . name
            throw Error("No word from RAM that the send finished (no Download Data or Database is Up To Date record in " . StreamFile . " within " . (SendTimeoutMs // 1000) . " seconds"
                . (missing != "" ? " for " . missing : "") . ")."
                . (others != "" ? " RAM reported for " . others . " instead: check GateRowsY in dk_sync.ini." : ""))
        }
        Sleep(1000)
        try {
            f := FileOpen(StreamFile, "r")
            if (f.Length < offset)
                offset := 0   ; the file was rotated: read the new one from the start
            f.Pos := offset
            text := f.Read()
            offset := f.Pos
            f.Close()
        } catch {
            continue   ; the NAS may be busy; try again
        }
        if (text = "")
            continue   ; nothing new yet
        lines := StrSplit(partial . text, "`n")
        partial := lines.Pop()   ; a record still being written
        for line in lines {
            line := RTrim(line, "`r")
            type := Trim(SubStr(line, 45, 13))
            if (type != "RS232" && type != "Network")
                continue
            account := Trim(SubStr(line, 1, 23))
            message := Trim(SubStr(line, 66))
            if !RegExMatch(message, "i)^(Failed To Connect|Download Data|Database is Up To Date)")
                continue
            if (GateAccounts.Length && !IsGateAccount(account)) {
                WriteLog("RAM reports for " . account . ", which isn't in GateAccounts: " . message)
                if !InStr(", " . others . ", ", ", " . account . ", ")
                    others .= (others = "" ? "" : ", ") . account
                continue
            }
            if RegExMatch(message, "i)^Failed To Connect") {
                WriteLog("RAM reports for " . account . ": " . message)
                throw Error("RAM could not reach the panel for " . account . ": " . message)
            }
            if RegExMatch(message, "i)^(Download Data|Database is Up To Date)") {
                WriteLog("RAM reports for " . account . ": " . message)
                done[account] := message
                if (done.Count >= GateRowsY.Length)
                    return
            }
        }
    }
}

; Whether a record's account (RAM's name, cut to 23 characters) is one of GateAccounts.
IsGateAccount(account) {
    for name in GateAccounts
        if (SubStr(name, 1, 23) = account)   ; = ignores case
            return true
    return false
}

; The fallback when there is no Live Streaming file: follows the session by the status
; window's "Cancel Session" button, greyed out before the session starts, enabled while it
; runs, greyed out again when it is over. RAM can prepare for over a minute before the
; session starts, so a session never seen running within 30 seconds is taken as too quick
; to see, which may be too early. Returns the status window, or "" if it never appeared.
WaitForSessionButton() {
    statusDlg := ""
    started := false
    startBy := A_TickCount + 30000
    deadline := A_TickCount + 300000
    loop {
        if (A_TickCount >= deadline)
            throw Error("Timed out waiting for the Send Data session to finish.")
        if (hwnd := WinExist("ahk_class #32770 ahk_exe DoorKing32.exe", "Cancel Session")) {
            statusDlg := "ahk_id " . hwnd
            running := false
            try {
                cancelBtn := FindButton("Cancel Session", statusDlg)
                running := (cancelBtn != "" && ControlGetEnabled(cancelBtn, statusDlg))
            }
            if running
                started := true
            else if started
                break   ; it ran, and is over
            else if (A_TickCount >= startBy) {
                WriteLog("Send session never seen running; it may have been too quick to see.")
                break
            }
        }
        Sleep(250)
    }
    if (statusDlg = "")
        throw Error("The Send Data status window never appeared.")
    return statusDlg
}

; --- RAM WATCH AND HEARTBEAT ---
CheckRam() {
    global Busy, RamLastStart, RamPath
    running := WinExist(RamWinTitle) ? true : false
    if running
        RamPath := WinGetProcessPath(RamWinTitle)
    WriteStatus(running)
    if running || Busy
        return
    if RamLastStart && (A_TickCount - RamLastStart < RamRetryMs)
        return

    RamLastStart := A_TickCount
    Busy := true
    try {
        WriteLog("RAM watch: RAM isn't running. Starting it.")
        StartRam()
        WriteLog("RAM watch: RAM started.")
        WriteStatus(true)
    } catch Error as err {
        WriteLog("RAM watch ERROR: " . err.Message)
    } finally {
        Busy := false
    }
}

; Starts RAM and minimizes it once it is up.
StartRam() {
    if (RamPath = "")
        throw Error("RAM's program path isn't known (set RamPath in dk_sync.ini, or start RAM by hand once).")
    SplitPath(RamPath, , &dir)
    Run('"' . RamPath . '"', dir)
    if !WinWait(RamWinTitle, , 60)
        throw Error("RAM didn't start within 60 seconds.")
    Sleep(5000)
    WinMinimize(RamWinTitle)   ; nobody works in RAM at the console; keep it out of the way
}

; Rewrites the heartbeat file (key=value lines), through a temp file so the server never
; reads half of it.
WriteStatus(running) {
    global StatusFileOk
    tmp := StatusFile . ".tmp"
    try {
        if FileExist(tmp)
            FileDelete(tmp)
        FileAppend("written=" . FormatTime(, "yyyy-MM-dd HH:mm:ss") . "`r`nhost=" . A_ComputerName
            . "`r`nenvironment=" . Environment . "`r`nram_running=" . (running ? "1" : "0") . "`r`n", tmp)
        FileMove(tmp, StatusFile, 1)
        if !StatusFileOk
            WriteLog("RAM watch: heartbeat written again.")
        StatusFileOk := true
    } catch Error as err {
        if StatusFileOk
            WriteLog("RAM watch: can't write " . StatusFile . " (" . err.Message . "). Does the folder exist?")
        StatusFileOk := false
    }
}

; Returns the ClassNN of the button in win whose text is text ("&" shortcuts ignored), or "".
FindButton(text, win) {
    for ctl in WinGetControls(win) {
        if !InStr(ctl, "Button")
            continue
        try {
            if (StrReplace(ControlGetText(ctl, win), "&") = text)
                return ctl
        }
    }
    return ""
}

; Runs a command from RAM's menu bar by the items' names, as a click on it would, for example
; RunRamMenu("File", "Import", "Import Now"). A name matches an item's text without its "&"
; shortcut marker, a trailing "..." and the shortcut key after a tab, ignoring case. If an
; item isn't found, the error lists the items that menu has, so the name can be corrected.
; (Replaces clicks at screen positions, which missed on the workbench PC; TODO #2.)
RunRamMenu(path*) {
    hwnd := WinExist(RamWinTitle)
    if !hwnd
        throw Error("DoorKing RAM is not running.")
    menu := DllCall("GetMenu", "Ptr", hwnd, "Ptr")
    if !menu
        throw Error("RAM's window has no menu bar.")
    for i, name in path {
        found := -1
        names := ""
        loop DllCall("GetMenuItemCount", "Ptr", menu, "Int") {
            text := MenuItemText(menu, A_Index - 1)
            if (text = "")
                continue   ; a separator
            names .= (names = "" ? "" : ", ") . text
            if (found < 0 && text = name)   ; = ignores case
                found := A_Index - 1
        }
        where := (i > 1) ? " under '" . path[i - 1] . "'" : ""
        if (found < 0)
            throw Error("RAM's menu has no '" . name . "'" . where . ". Its items: " . names)
        if (i < path.Length) {
            menu := DllCall("GetSubMenu", "Ptr", menu, "Int", found, "Ptr")
            if !menu
                throw Error("RAM's menu item '" . name . "'" . where . " has no submenu.")
        } else {
            id := DllCall("GetMenuItemID", "Ptr", menu, "Int", found, "UInt")
            if (id = 0xFFFFFFFF)
                throw Error("RAM's menu item '" . name . "'" . where . " opens a submenu, not a command.")
            PostMessage(0x111, id, 0, , "ahk_id " . hwnd)   ; WM_COMMAND
        }
    }
}

; The text of a menu item (by position) as RunRamMenu compares it, or "" for a separator.
MenuItemText(menu, pos) {
    len := DllCall("GetMenuString", "Ptr", menu, "UInt", pos, "Ptr", 0, "Int", 0, "UInt", 0x400, "Int")  ; MF_BYPOSITION
    if (len <= 0)
        return ""
    buf := Buffer((len + 1) * 2, 0)
    DllCall("GetMenuString", "Ptr", menu, "UInt", pos, "Ptr", buf, "Int", len + 1, "UInt", 0x400, "Int")
    text := StrGet(buf)
    if (tab := InStr(text, "`t"))
        text := SubStr(text, 1, tab - 1)
    text := StrReplace(StrReplace(StrReplace(text, "&&", Chr(1)), "&"), Chr(1), "&")
    return Trim(RegExReplace(text, "(\.\.\.|" . Chr(0x2026) . ")$"))   ; "..." or an ellipsis
}

; Logs every control in win: its ClassNN, position, text, and items when it is a list that
; can be read (a list box, combo box or list view). Temporary, see above.
LogControls(win, label) {
    try {
        for ctl in WinGetControls(win) {
            line := label . " control " . ctl
            try {
                ControlGetPos(&x, &y, &w, &h, ctl, win)
                line .= " at " . x . "," . y . " size " . w . "x" . h
            }
            try line .= " text '" . SubStr(RegExReplace(ControlGetText(ctl, win), "[\r\n]+", " "), 1, 200) . "'"
            try {
                items := ControlGetItems(ctl, win)
                list := ""
                for item in items
                    list .= (list = "" ? "" : " | ") . item
                line .= " items (" . items.Length . "): " . SubStr(list, 1, 500)
            }
            if InStr(ctl, "SysListView") {
                try line .= " rows: " . SubStr(RegExReplace(ListViewGetContent(, ctl, win), "\n", " | "), 1, 500)
            }
            WriteLog(line)
        }
    } catch Error as err {
        WriteLog("Could not list the controls of the " . label . " (" . err.Message . ").")
    }
}

WriteLog(Message) {
    timestamp := FormatTime(, "yyyy-MM-dd HH:mm:ss")
    FileAppend("[" . timestamp . "] " . Message . "`n", LogFile)
}

; --- EMERGENCY PANIC BUTTON ---
; Press Ctrl + Esc at any time to instantly kill the AHK script
^Esc::ExitApp
