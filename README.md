
# SyncEngine Installer

This is a lightweight PHP-based installer for setting up the **SyncEngine** application. It guides you through checking system requirements, authenticating with GitHub to fetch private release assets, downloading and extracting the chosen release, and preparing your environment.

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
   Copy `install.php` into your project’s `/public` directory.

2. **Open in browser**:  
   Navigate to the script in your browser, e.g.:
   ```
   http://localhost/your-project/public/install.php
   ```

3. **Step-by-step guide**:
    - **Step 1**: Server requirements check
    - **Step 2**:
        - Enter your **GitHub token**
        - Select a **release version**
        - Select an **asset** (e.g., `release.zip`)
        - The selected asset is downloaded and extracted to the correct folder

4. **Finish**:  
   You’ll be redirected to `/install` to complete application setup (e.g., `.env` configuration or DB migration).

---
