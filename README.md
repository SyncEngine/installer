
# SyncEngine Installer

This is a lightweight PHP-based installer for setting up the **SyncEngine** application. It guides you through selecting a release version, checking system requirements, downloading and extracting the chosen release, and preparing your environment.

---

## 📁 File Structure

The installer expects the following structure:

```
your-project/
├── public/
│   └── install.php   ← This script must be placed here
├── [SyncEngine Files Will Be Extracted Here]
```

> ⚠️ `install.php` must be placed in the `/public` folder. The downloaded files will be extracted **one directory above** the script (i.e., the project root).

---

## 🔧 Usage

1. **Place the script**:  
   Copy `install.php` into your project's `/public` directory.

2. **Open in browser**:  
   Navigate to the script in your browser, e.g.:
   ```
   http://localhost/your-project/public/install.php
   ```

3. **Step-by-step guide**:
    - **Step 1**: Select a release version
        - Enter your **GitHub token** for private repository access
        - Select a **release version** from the dropdown
        - Click "Continue" to proceed
    - **Step 2**: System requirements check
        - Requirements are fetched dynamically from the selected release's `requirements.json`
        - Falls back to `composer.json` if `requirements.json` is not present
        - Review PHP version and extensions, then click "Continue" or "Cancel"
    - **Step 3**: Download and extract
        - Downloads `release.zip` from the selected GitHub release
        - Validates extracted directory structure
        - Automatically extracts files to project root after confirmation
        - Redirects to Step 4 on success
    - **Step 4**: Installation complete
        - Click "Go to SyncEngine Installer" to continue
        - Installer files are automatically cleaned up before redirect

4. **Finish**:  
   Complete application setup (`.env` configuration, database migrations, etc.) through the Symfony installer at `/install`.

---

## 🎨 Features

- **Dark/Light Mode**: Toggle theme using the button in the top-right corner. Preference is saved to localStorage and respects system settings on first visit.
- **State Management**: Installation progress and selected release are cached in `install-state.json` for persistence across page reloads.
- **Dynamic Requirements**: System requirements are fetched from the selected release tag, ensuring accuracy for each version.
- **GitHub API Integration**: Uses GitHub's REST API to fetch releases and assets, with raw file access for `requirements.json`.

---

## 🔄 State File

The installer uses `install-state.json` in the same directory to persist:
- Selected release ID
- Cached releases list from GitHub
- Installation progress (current step)

You can manually edit or delete this file to reset the installation state. A "Refresh" button is available in Step 1 to reload the releases list from GitHub.

---

## 🐛 Troubleshooting

- **GitHub API rate limit**: If you see rate limiting errors, ensure your GitHub token has proper permissions.
- **Requirements mismatch**: The installer validates against the selected release's requirements, not the latest version.
- **Extraction issues**: Check that the project directory is writable and has sufficient disk space.
