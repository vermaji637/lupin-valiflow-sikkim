# Start ValiFlow DOCX Python service (Windows)
$port = if ($env:PORT) { $env:PORT } else { 8001 }

Write-Host "Stopping any process on port $port..."
$connections = netstat -ano | Select-String ":$port\s"
foreach ($line in $connections) {
    if ($line -match '\s(\d+)\s*$') {
        $procId = $Matches[1]
        if ($procId -ne '0') {
            taskkill /PID $procId /F 2>$null
        }
    }
}

Start-Sleep -Seconds 1

Set-Location $PSScriptRoot
Write-Host "Starting uvicorn on http://127.0.0.1:$port ..."
& .\venv\Scripts\uvicorn.exe app:app --host 127.0.0.1 --port $port
