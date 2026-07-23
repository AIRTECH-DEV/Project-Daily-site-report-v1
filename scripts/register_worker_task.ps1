# Registers a Windows Scheduled Task that runs the PMS worker every 1 minute as a
# reliable safety net (the submit also spawns the worker immediately; this catches
# anything the spawn missed and drives the delayed email/WhatsApp).
#
# Run once in an ELEVATED PowerShell:
#   powershell -ExecutionPolicy Bypass -File scripts\register_worker_task.ps1
#
# Remove with:  schtasks /Delete /TN "PMS Worker" /F

$php    = "C:\xampp\php\php.exe"
$script = "C:\xampp\htdocs\pms\scripts\worker.php"

$action  = New-ScheduledTaskAction -Execute $php -Argument "`"$script`" --once"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
           -RepetitionInterval (New-TimeSpan -Minutes 1) `
           -RepetitionDuration ([TimeSpan]::MaxValue)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable `
           -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 20)

Register-ScheduledTask -TaskName "PMS Worker" -Action $action -Trigger $trigger `
    -Settings $settings -Description "Drains the PMS submission queue (core + notifications)." -Force

Write-Host "Registered 'PMS Worker' (runs worker.php --once every 1 minute)."
