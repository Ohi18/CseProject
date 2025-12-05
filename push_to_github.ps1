# Script to push GoGlam project to GitHub
# Repository: https://github.com/Ohi18/CseProject.git

$workspacePath = "C:\Users\OHI\Desktop\goglam"
$githubRepoPath = "C:\Users\OHI\Documents\Github\goglam"
$remoteUrl = "https://github.com/Ohi18/CseProject.git"

Write-Host "Starting deployment to GitHub..." -ForegroundColor Cyan

# Step 1: Copy all files to GitHub repository
Write-Host "Copying files to GitHub repository..." -ForegroundColor Yellow
Copy-Item -Path "$workspacePath\*" -Destination "$githubRepoPath\" -Recurse -Force -Exclude ".git"
Write-Host "Files copied successfully" -ForegroundColor Green

# Step 2: Navigate to GitHub repository
Set-Location $githubRepoPath
Write-Host "Changed to repository directory: $githubRepoPath" -ForegroundColor Yellow

# Step 3: Find Git executable
$gitPath = $null
$possibleGitPaths = @(
    "C:\Program Files\Git\bin\git.exe",
    "C:\Program Files (x86)\Git\bin\git.exe",
    "$env:LOCALAPPDATA\Programs\Git\bin\git.exe",
    "$env:ProgramFiles\Git\bin\git.exe",
    "$env:ProgramFiles(x86)\Git\bin\git.exe",
    "git"
)

foreach ($path in $possibleGitPaths) {
    if ($path -eq "git") {
        try {
            $null = Get-Command git -ErrorAction Stop
            $gitPath = "git"
            break
        } catch {
            continue
        }
    } else {
        if (Test-Path $path) {
            $gitPath = $path
            Write-Host "Found Git at: $path" -ForegroundColor Green
            break
        }
    }
}

if ($null -eq $gitPath) {
    Write-Host "ERROR: Git not found. Please install Git from https://git-scm.com/download/win" -ForegroundColor Red
    Write-Host "Files have been copied to: $githubRepoPath" -ForegroundColor Yellow
    Write-Host "After installing Git, run these commands:" -ForegroundColor Yellow
    Write-Host "  cd $githubRepoPath" -ForegroundColor Cyan
    Write-Host "  git remote set-url origin $remoteUrl" -ForegroundColor Cyan
    Write-Host "  git add ." -ForegroundColor Cyan
    Write-Host "  git commit -m 'Initial commit'" -ForegroundColor Cyan
    Write-Host "  git push -u origin main" -ForegroundColor Cyan
    exit 1
}

# Function to run git commands
function Invoke-Git {
    param([string[]]$Arguments)
    if ($gitPath -eq "git") {
        & git $Arguments
    } else {
        & $gitPath $Arguments
    }
}

# Step 4: Check if .git exists, if not initialize
if (-not (Test-Path ".git")) {
    Write-Host "Initializing git repository..." -ForegroundColor Yellow
    Invoke-Git -Arguments @("init")
    Write-Host "Git repository initialized" -ForegroundColor Green
}

# Step 5: Set remote URL
Write-Host "Setting remote URL..." -ForegroundColor Yellow
$currentRemote = Invoke-Git -Arguments @("remote", "get-url", "origin") 2>&1
if ($LASTEXITCODE -ne 0 -or $currentRemote -ne $remoteUrl) {
    Invoke-Git -Arguments @("remote", "remove", "origin") 2>&1 | Out-Null
    Invoke-Git -Arguments @("remote", "add", "origin", $remoteUrl)
    Write-Host "Remote URL set to: $remoteUrl" -ForegroundColor Green
} else {
    Write-Host "Remote URL already set correctly" -ForegroundColor Green
}

# Step 6: Check git status
Write-Host "Checking git status..." -ForegroundColor Yellow
$status = Invoke-Git -Arguments @("status", "--porcelain")
if ($null -eq $status -or $status.Count -eq 0) {
    Write-Host "No changes to commit" -ForegroundColor Yellow
} else {
    # Step 7: Add all files
    Write-Host "Adding files to git..." -ForegroundColor Yellow
    Invoke-Git -Arguments @("add", ".")
    Write-Host "Files added" -ForegroundColor Green
    
    # Step 8: Commit changes
    Write-Host "Committing changes..." -ForegroundColor Yellow
    $dateStr = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $commitMessage = "Update project files - $dateStr"
    Invoke-Git -Arguments @("commit", "-m", $commitMessage)
    Write-Host "Changes committed" -ForegroundColor Green
}

# Step 9: Check current branch
$currentBranch = Invoke-Git -Arguments @("branch", "--show-current") 2>&1
if ([string]::IsNullOrWhiteSpace($currentBranch)) {
    $currentBranch = "main"
    Invoke-Git -Arguments @("checkout", "-b", "main") | Out-Null
    Write-Host "Created and switched to 'main' branch" -ForegroundColor Green
}

# Step 10: Push to remote
Write-Host "Pushing to GitHub repository..." -ForegroundColor Yellow
Write-Host "Repository: $remoteUrl" -ForegroundColor Cyan
Write-Host "Branch: $currentBranch" -ForegroundColor Cyan

$pushResult = Invoke-Git -Arguments @("push", "-u", "origin", $currentBranch) 2>&1

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "SUCCESS! Project pushed to GitHub!" -ForegroundColor Green
    Write-Host "View your repository at: $remoteUrl" -ForegroundColor Cyan
} else {
    Write-Host ""
    Write-Host "Push attempt completed. Output:" -ForegroundColor Yellow
    Write-Host $pushResult -ForegroundColor Yellow
    
    if ($pushResult -match "authentication") {
        Write-Host ""
        Write-Host "Authentication required. You may need to:" -ForegroundColor Yellow
        Write-Host "1. Use a Personal Access Token instead of password" -ForegroundColor Cyan
        Write-Host "2. Set up SSH keys" -ForegroundColor Cyan
        Write-Host "3. Use GitHub Desktop or another Git client" -ForegroundColor Cyan
    }
}

Write-Host ""
Write-Host "Deployment process completed!" -ForegroundColor Cyan


