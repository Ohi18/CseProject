# GitHub Setup Instructions

## Current Status
✅ All project files have been copied to: `C:\Users\OHI\Documents\Github\goglam`
✅ Repository is ready to push to: `https://github.com/Ohi18/CseProject.git`

## Next Steps

### Option 1: Install Git (Recommended)

1. **Download Git for Windows:**
   - Visit: https://git-scm.com/download/win
   - Download and run the installer

2. **During Installation:**
   - ✅ Check "Add Git to PATH" (important!)
   - Use default settings for everything else

3. **After Installation:**
   - Close and reopen PowerShell/Command Prompt
   - Run the deployment script again: `.\push_to_github.ps1`
   - Or manually run these commands:

```bash
cd C:\Users\OHI\Documents\Github\goglam
git remote set-url origin https://github.com/Ohi18/CseProject.git
git add .
git commit -m "Initial commit - GoGlam project"
git branch -M main
git push -u origin main
```

### Option 2: Use GitHub Desktop (Easier GUI)

1. **Download GitHub Desktop:**
   - Visit: https://desktop.github.com/
   - Install GitHub Desktop

2. **Add Repository:**
   - Open GitHub Desktop
   - File → Add Local Repository
   - Browse to: `C:\Users\OHI\Documents\Github\goglam`
   - Click "Add Repository"

3. **Set Remote:**
   - Repository → Repository Settings → Remote
   - Set Primary remote to: `https://github.com/Ohi18/CseProject.git`

4. **Commit and Push:**
   - You'll see all your files listed as changes
   - Enter commit message: "Initial commit - GoGlam project"
   - Click "Commit to main"
   - Click "Publish branch" or "Push origin"

### Option 3: Manual Git Commands (If Git is installed but not in PATH)

If Git is installed but not in PATH, find it first:

```powershell
# Common Git locations:
# C:\Program Files\Git\bin\git.exe
# C:\Program Files (x86)\Git\bin\git.exe

# Then use full path:
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\OHI\Documents\Github\goglam" remote set-url origin https://github.com/Ohi18/CseProject.git
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\OHI\Documents\Github\goglam" add .
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\OHI\Documents\Github\goglam" commit -m "Initial commit"
& "C:\Program Files\Git\bin\git.exe" -C "C:\Users\OHI\Documents\Github\goglam" push -u origin main
```

## Authentication

When pushing, you may be prompted for credentials:
- **Username:** Your GitHub username (Ohi18)
- **Password:** Use a **Personal Access Token** (not your GitHub password)
  - Create one at: https://github.com/settings/tokens
  - Select scopes: `repo` (full control of private repositories)

## Files Ready to Push

All these files are in the repository folder:
- ✅ All PHP files (dashboard.php, login.php, registration.php, etc.)
- ✅ Image assets (customer-icon.png, goglam-logo.png, saloon-icon.png)
- ✅ Database setup files
- ✅ Deployment scripts

## Quick Reference

**Repository URL:** https://github.com/Ohi18/CseProject.git  
**Local Path:** C:\Users\OHI\Documents\Github\goglam  
**Deployment Script:** push_to_github.ps1 (run after Git is installed)


