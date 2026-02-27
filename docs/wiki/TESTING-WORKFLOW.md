# Testing Workflow Guide

**Purpose**: Standard procedures for testing Razy modules during development.

---

## Test Environment

| Component | Value |
|-----------|-------|
| PHP | `C:\MAMP\bin\php\php8.3.1\php.exe` |
| Server | Built-in PHP dev server |
| Port | 8080 |
| Test Site | `test-razy-cli/sites/mysite/` |

---

## Quick Start

### 1. Start Test Server

```powershell
cd c:\Users\RayFung\VSCode-Projects\Razy\test-razy-cli
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080
```

### 2. Test Endpoints

```powershell
# Create WebClient for quick testing
$wc = New-Object System.Net.WebClient

# Test a route
$wc.DownloadString("http://localhost:8080/module/route")
```

### 3. Stop Server

Press `Ctrl+C` in the terminal.

---

## Quick Testing with runapp

For fast module testing without web server, use the `runapp` interactive shell:

```powershell
# Start interactive shell
php Razy.phar runapp appdemo

# In shell:
[appdemo]> routes    # List routes
[appdemo]> modules   # List modules
[appdemo]> api       # List API modules
[appdemo]> info      # Show distributor info
[appdemo]> run /hello/World  # Execute a route
[appdemo]> call demo_api getData  # Call API command
[appdemo]> exit      # Exit shell
```

**Scripted Testing** (pipe commands):
```powershell
@("routes", "modules", "exit") | php Razy.phar runapp appdemo
```

---

## Testing Patterns

### JSON Endpoint

```powershell
$response = $wc.DownloadString("http://localhost:8080/module/api")
$json = $response | ConvertFrom-Json
$json.status  # Access response field
```

### HTML Endpoint

```powershell
$html = (Invoke-WebRequest -Uri "http://localhost:8080/module/" -UseBasicParsing).Content
```

### Route with Parameter

```powershell
# Test :d (digit) parameter
$wc.DownloadString("http://localhost:8080/route_demo/user/123")

# Test :a (any) parameter
$wc.DownloadString("http://localhost:8080/route_demo/article/my-title")
```

### Event Endpoint

```powershell
# Fire event and check listener responses
$response = $wc.DownloadString("http://localhost:8080/event_demo/fire")
$json = $response | ConvertFrom-Json
$json.responses.Count  # Number of listeners that responded
```

---

## Test Validation Checklist

### Module Loading
- [ ] No PHP errors in server output
- [ ] Main route (`/module/`) returns content
- [ ] Module shows in loaded modules list

### Route Testing
- [ ] Valid parameters return expected response
- [ ] Invalid patterns return 404 (e.g., `/user/abc` for `:d` route)
- [ ] Edge cases work (empty, max length)

### Event Testing
- [ ] Event fires without errors
- [ ] Listeners receive correct data
- [ ] Response contains listener outputs
- [ ] Multiple listeners all respond

### Error Handling
- [ ] Invalid routes return 404
- [ ] Missing required params handled
- [ ] Malformed input doesn't crash

---

## Common Test Scenarios

### Test All Routes in Module

```powershell
$baseUrl = "http://localhost:8080/route_demo"
$wc = New-Object System.Net.WebClient

# Main route
$wc.DownloadString("$baseUrl/")

# Parameterized routes
$wc.DownloadString("$baseUrl/user/123")
$wc.DownloadString("$baseUrl/article/test-slug")
$wc.DownloadString("$baseUrl/product/shoes")
```

### Test Event Flow

```powershell
# Fire event from sender module
$wc.DownloadString("http://localhost:8080/event_demo/fire")

# Check receiver module processed it
$wc.DownloadString("http://localhost:8080/event_receiver/status")
```

### Test Invalid Input

```powershell
# Should fail - :d expects digits
try {
    $wc.DownloadString("http://localhost:8080/route_demo/user/abc")
} catch {
    "Got expected 404: $_"
}

# Should fail - code requires exactly 6 chars
try {
    $wc.DownloadString("http://localhost:8080/route_demo/code/AB")
} catch {
    "Got expected 404: $_"
}
```

---

## Debugging Tips

### Server Shows Errors
Check the terminal running the PHP server for:
- Fatal errors (syntax, class not found)
- Warnings (undefined variables)
- Stack traces

### Route Not Found (404)
1. Verify leading slash: `/route_demo/user/(:d)`
2. Check parentheses for capture: `(:d)` not `:d`
3. Confirm module registered in `dist.php`
4. Check controller class name matches file name

### Event Not Firing
1. Verify event format: `vendor/module:event_name`
2. Check listener registered in `__onInit()`
3. Ensure using inline closure, not file handler
4. Confirm receiver module in `dist.php`

### Empty Response
1. Check handler returns value (not just echoes)
2. Verify method name matches route handler
3. Check for PHP notices suppressing output

---

## Server Management

### Running in Background

```powershell
Start-Process -FilePath "C:\MAMP\bin\php\php8.3.1\php.exe" -ArgumentList "-S", "localhost:8080" -WorkingDirectory "test-razy-cli" -WindowStyle Hidden
```

### Find and Kill Server

```powershell
# Find process
Get-Process -Name php | Where-Object { $_.Path -like "*php8.3.1*" }

# Kill process
Stop-Process -Name php -Force
```

### Check If Server Running

```powershell
Test-NetConnection -ComputerName localhost -Port 8080
```
