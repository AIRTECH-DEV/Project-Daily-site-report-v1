# Registers a Windows Scheduled Task that runs the PE-plan reminder sender every
# 15 minutes. The script itself is time-gated: it only actually sends once per
# day, at/after pe_plan.send_time (set in the admin panel), and stamps the day so
# re-runs are no-ops. Running every 15 min just means "changing the send time in
# admin takes effect without re-registering this task".
#
# Run once in an ELEVATED PowerShell:
#   powershell -ExecutionPolicy Bypass -File scripts\register_pe_plan_task.ps1
#
# Remove with:  schtasks /Delete /TN "PMS PE Plan Reminder" /F

$php    = "C:\xampp\php\php.exe"
$script = "C:\xampp\htdocs\pms\scripts\pe_plan_send.php"

$action  = New-ScheduledTaskAction -Execute $php -Argument "`"$script`""
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
           -RepetitionInterval (New-TimeSpan -Minutes 15) `
           -RepetitionDuration ([TimeSpan]::MaxValue)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable `
           -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask -TaskName "PMS PE Plan Reminder" -Action $action -Trigger $trigger `
    -Settings $settings -Description "Sends tomorrow's PE site-plan image on WhatsApp, one day before (time-gated)." -Force

Write-Host "Registered 'PMS PE Plan Reminder' (runs pe_plan_send.php every 15 min; sends once/day at send_time)."
