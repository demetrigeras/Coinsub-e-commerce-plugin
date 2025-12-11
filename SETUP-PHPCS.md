# WordPress Coding Standards Setup Guide

## ✅ Recommended: GitHub Actions Only

**This is the safest approach** - all checks run automatically on GitHub, no local setup required.

The repository includes `.github/workflows/phpcs.yml` which will automatically:
- ✅ Run WordPress Coding Standards checks on every push/PR
- ✅ Show results in GitHub Actions tab
- ✅ Can be set as a required status check
- ✅ Prevents merging code that doesn't meet standards

---
//
## 🚀 Quick Setup (GitHub Actions)

### Step 1: Push the Workflow File
```bash
git add .github/workflows/phpcs.yml .phpcs.xml composer.json
git commit -m "Add WordPress Coding Standards CI/CD"
git push
```

### Step 2: Enable GitHub Actions
1. Go to your repository on GitHub
2. Click **Settings → Actions → General**
3. Under "Workflow permissions", select **"Read and write permissions"**
4. Check **"Allow GitHub Actions to create and approve pull requests"**
5. Click **Save**

### Step 3: Wait for First Run
1. Go to **Actions** tab in your repository
2. You should see "WordPress Coding Standards" workflow running
3. Wait for it to complete (usually 1-2 minutes)

### Step 4: Add as Required Status Check
1. Go to **Settings → Branches**
2. Click **Edit** on your branch protection rule (main/master)
3. Scroll to **"Require status checks to pass before merging"**
4. Check the box
5. In the search box, type: `PHP_CodeSniffer`
6. Select **"PHP_CodeSniffer"** from the list
7. Click **Save changes**

**Done!** Now all pull requests will be automatically checked.

---

## 📋 How It Works

### On Every Push/PR:
1. GitHub Actions automatically runs
2. Installs PHP_CodeSniffer and WordPress Coding Standards
3. Scans all PHP files in your repository
4. Reports any violations in the Actions tab
5. If there are errors, the check fails (red X)
6. If everything passes, the check succeeds (green ✓)

### Viewing Results:
- Go to **Actions** tab → Click on the workflow run
- See detailed output of what was checked
- See any violations with file names and line numbers

---

## 🔧 Optional: Local Setup (For Development Only)

**Note:** This is optional. GitHub Actions will catch everything, but you can also check locally before pushing.

#### Step 1: Install Composer (if not installed)
```bash
# macOS
brew install composer

# Linux
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

#### Step 2: Install Dependencies
```bash
cd /path/to/coinsubCommerce
composer install
```

#### Step 3: Run PHPCS
```bash
# Check for coding standards violations
composer run phpcs

# Auto-fix issues (where possible)
composer run phpcbf
```

---

## Manual Installation (Alternative)

### Install PHP_CodeSniffer
```bash
composer global require squizlabs/php_codesniffer
```

### Install WordPress Coding Standards
```bash
composer global require wp-coding-standards/wpcs
phpcs --config-set installed_paths $HOME/.composer/vendor/wp-coding-standards/wpcs
```

### Run Checks
```bash
# From project root
phpcs --standard=.phpcs.xml

# Or use the full path
$HOME/.composer/vendor/bin/phpcs --standard=.phpcs.xml
```

---

## Configuration

The `.phpcs.xml` file is already configured with:
- ✅ WordPress Coding Standards
- ✅ Excludes vendor/node_modules
- ✅ Text domain: "coinsub"
- ✅ Allows short array syntax
- ✅ Minimum WP version: 5.0

**To customize**, edit `.phpcs.xml`:
```xml
<!-- Add more exclusions -->
<exclude-pattern>/tests/*</exclude-pattern>

<!-- Change text domain -->
<property name="text_domain" value="your-domain"/>

<!-- Add more rules -->
<rule ref="WordPress.WP.I18n"/>
```

---

## Common Issues & Fixes

### Issue: "Could not find WordPress Coding Standards"
**Fix:**
```bash
composer global require wp-coding-standards/wpcs
phpcs --config-set installed_paths $HOME/.composer/vendor/wp-coding-standards/wpcs
```

### Issue: "Text domain mismatch"
**Fix:** Update `.phpcs.xml`:
```xml
<property name="text_domain" value="coinsub"/>
```

### Issue: "Short array syntax not allowed"
**Fix:** Already excluded in `.phpcs.xml`, but if you see errors:
```xml
<exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
```

---

## Auto-Fix Common Issues

Run `phpcbf` to automatically fix:
- Indentation
- Spacing
- Line endings
- Some formatting issues

```bash
composer run phpcbf
```

**Note:** Always review auto-fixes before committing!

---

## GitHub Actions Setup

### Step 1: Enable GitHub Actions
1. Go to repository **Settings → Actions → General**
2. Enable "Allow all actions and reusable workflows"

### Step 2: Add as Required Check
1. Go to **Settings → Branches**
2. Edit branch protection rule
3. Under "Require status checks to pass"
4. Add "PHP_CodeSniffer" to required checks

### Step 3: Test
1. Create a test branch
2. Make a change
3. Create a pull request
4. Check Actions tab for PHPCS results

---

## VS Code Integration (Optional)

### Install PHPCS Extension
1. Install "PHP Sniffer & Beautifier" extension
2. Add to `.vscode/settings.json`:
```json
{
    "phpSniffer.standard": ".phpcs.xml",
    "phpSniffer.executablesFolder": "./vendor/bin",
    "phpSniffer.autoDetect": true
}
```

---

## Pre-commit Hook (Optional)

Create `.git/hooks/pre-commit`:
```bash
#!/bin/bash
composer run phpcs
if [ $? != 0 ]; then
    echo "PHPCS failed! Run 'composer run phpcbf' to auto-fix."
    exit 1
fi
```

Make it executable:
```bash
chmod +x .git/hooks/pre-commit
```

---

## ✅ Summary

**Primary Method (Recommended):**
- ✅ **GitHub Actions** - Automatic checks on every push/PR
- ✅ **Required Status Check** - Prevents merging non-compliant code
- ✅ **No Local Setup Needed** - Everything runs on GitHub

**Optional (For Development):**
- ⚠️ **Local Checks** - Run `composer run phpcs` before pushing (optional)
- ⚠️ **Auto-Fix** - Run `composer run phpcbf` to fix issues (optional)

**Next Steps:**
1. ✅ Push `.github/workflows/phpcs.yml` to repository
2. ✅ Enable GitHub Actions in Settings
3. ✅ Add "PHP_CodeSniffer" as required status check
4. ✅ All future PRs will be automatically validated!

---

## 🎯 Benefits of GitHub-Only Approach

✅ **Safety** - No risk of local configuration issues  
✅ **Consistency** - Everyone gets the same checks  
✅ **Automatic** - No need to remember to run checks  
✅ **Enforced** - Can't merge code that fails checks  
✅ **Transparent** - Results visible to all team members  
✅ **No Setup** - Works immediately after pushing workflow file

