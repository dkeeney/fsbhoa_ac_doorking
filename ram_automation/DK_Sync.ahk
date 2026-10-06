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

if (Environment != "testbed" && Environment != "production") {
    MsgBox("DK_Sync: Environment must be 'testbed' or 'production', not '" . Environment . "'. The script will exit.", "DK_Sync", "Iconx")
    ExitApp
}

; --- CONFIGURATION ---
global WatchDir    := BaseDir . "\To_RAM_" . StrTitle(Environment)
global LogDir      := BaseDir . "\Logs"
global FlagFile    := WatchDir . "\import_now.flag"
global CsvFile     := WatchDir . "\updates.csv"
global LogFile     := LogDir . "\automation_" . Environment . ".log"
global RamWinTitle := "ahk_class DoorKing32AppClass ahk_exe DoorKing32.exe"

; Ensure directories exist on startup
if !DirExist(WatchDir)
    DirCreate(WatchDir)
if !DirExist(LogDir)
    DirCreate(LogDir)

WriteLog("AHK Sync Script Started (" . Environment . ", accepting triggers from " . ExpectedHost . "). Watching " . WatchDir)
SetTimer(WatchFolder, 3000)

WatchFolder() {
    if !FileExist(FlagFile)
        return

    SetTimer(WatchFolder, 0)

    ; --- ENVIRONMENT GUARD ---
    ; The trigger file must say it came from this PC's environment and expected host.
    ; A rejected trigger is renamed (not deleted) so it can be investigated, and the CSV is left alone.
    reason := CheckTrigger()
    if (reason != "") {
        WriteLog("REJECTED trigger: " . reason)
        FileMove(FlagFile, WatchDir . "\import_now.rejected_" . FormatTime(, "yyyyMMdd_HHmmss"), 1)
        SetTimer(WatchFolder, 3000)
        return
    }
    
    if !FileExist(CsvFile) {
        WriteLog("WARNING: Flag file found, but " . CsvFile . " is missing. Aborting run.")
        FileDelete(FlagFile)
        SetTimer(WatchFolder, 3000)
        return
    }

    WriteLog("Trigger file and CSV detected. Starting import process.")

    try {
        RunAutomation()
        WriteLog("SUCCESS: Full Import and Network Send sequence completed.")
    } catch Error as err {
        WriteLog("ERROR: " . err.Message)
    }

    ; Cleanup
    if FileExist(FlagFile)
        FileDelete(FlagFile)
        
    if FileExist(CsvFile)
        FileMove(CsvFile, WatchDir . "\updates_processed_" . FormatTime(, "yyyyMMdd_HHmmss") . ".csv", 1)

    SetTimer(WatchFolder, 3000)
}

CheckTrigger() {
    fields := Map()
    for line in StrSplit(FileRead(FlagFile), "`n", "`r") {
        parts := StrSplit(line, "=", " `t", 2)
        if (parts.Length = 2)
            fields[StrLower(parts[1])] := StrLower(parts[2])
    }
    env  := fields.Has("env")  ? fields["env"]  : ""
    host := fields.Has("host") ? fields["host"] : ""
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
    MouseMove(138, 60)  ;  Click 'View' X, Y
    Sleep(200)
    Click("Down")
    Sleep(100)
    Click("Up")
    Sleep(500)
    
    MouseMove(200, 140)  ;   Click 'View > Accounts' X, Y
    Sleep(200)
    Click("Down")
    Sleep(300)
    Click("Up")
    Sleep(1500)
    
    Send("{Esc 2}")
    Sleep(500)

    ; --- 4. OPEN IMPORT WIZARD ---
    WriteLog("Opening File -> Import -> Import Now")
    
    MouseMove(34, 60, 5)   ; Click 'File'
    Sleep(500)
    Click("Down")
    Sleep(150)
    Click("Up")
    Sleep(1500)
    
    MouseMove(92, 299, 10)  ; Click 'Import'
    Sleep(1500)
    
    MouseMove(420, 300, 10) ; Click 'Import Now'
    Sleep(500)
    Click()
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
    
    MouseMove(195, 59)   ; Click 'Action'
    Sleep(200)
    Click("Down")
    Sleep(100)
    Click("Up")
    Sleep(500)
    
    MouseMove(298, 334)  ; Click 'Send Data Now...'
    Sleep(200)
    Click("Down")
    Sleep(100)
    Click("Up")
    Sleep(500)

    ; --- 8. CONFIGURE SEND DATA ---
    if !WinWaitActive("ahk_class #32770", , 5)
        throw Error("Send Data dialog did not appear.")
        
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
    
    WriteLog("Initiating transmission to gate controllers.")
    ControlFocus("Button1", "A") ; OK
    Sleep(500)
    ControlSend("{Space}", "Button1", "A")

    ; --- 9. WAIT FOR NETWORK SEND ---
    WriteLog("Data is transmitting to gates. Waiting for completion...")
    if WinWaitActive("ahk_class #32770", , 300) {
        WriteLog("Transmission complete. Closing status dialog.")
        Sleep(3000) ; Give the window a full second to settle
        
        ; 1. Try targeting Button2 directly
        try {
            ControlFocus("Button2", "A")
            Sleep(2000)
            ControlSend("{Space}", "Button2", "A")
        }
        Sleep(500)
        
        ; 2. If the box is STILL open, forcefully kill it by closing the window
        if WinExist("ahk_class #32770 ahk_exe DoorKing32.exe") {
            WriteLog("Enter key missed. Force-closing the status window.")
            WinClose("ahk_class #32770 ahk_exe DoorKing32.exe")
            Sleep(500)
        }
    } else {
        throw Error("Timed out waiting for Network Send completion dialog.")
    }

    ; --- 10. CLEANUP & MINIMIZE ---
    WriteLog("Automation successful. Minimizing DoorKing RAM.")
    Sleep(1000) ; Give the UI a second to settle after closing the dialog
    WinMinimize(RamWinTitle)
}
WriteLog(Message) {
    timestamp := FormatTime(, "yyyy-MM-dd HH:mm:ss")
    FileAppend("[" . timestamp . "] " . Message . "`n", LogFile)
}

; --- EMERGENCY PANIC BUTTON ---
; Press Ctrl + Esc at any time to instantly kill the AHK script
^Esc::ExitApp
