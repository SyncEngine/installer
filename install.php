<?php

const REQUIRED_PHP_VERSION = '8.2.0';
const REQUIRED_EXTENSIONS  = ['pdo', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'PCRE', 'Session'];
const OWNER                = 'SyncEngine';
const REPO                 = 'SyncEngine';
const GITHUB_API           = 'https://api.github.com/repos/' . OWNER . '/' . REPO . '/releases';

$currentStep = $_GET['step'] ?? 1;
$targetDir = realpath(__DIR__ . '/../') . '/';

$expectedDirName = 'public';

if (basename(__DIR__) !== $expectedDirName) {
    echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Error</title></head><body>
          <div style='margin:50px auto;max-width:600px;font-family:sans-serif;text-align:center;'>
          <h2 style='color:red;'>Error: install.php must be located inside the /public directory.</h2>
          <p>Please move <code>install.php</code> to the correct location and try again.</p>
          </div></body></html>";
    exit;
}

function headerHtml($title)
{
    echo "<!DOCTYPE html><html lang='en'><head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1'>
        <title>$title</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head><body class='bg-light'>
    <div class='container py-5' style='max-width: 700px;'>
    <div class='card'><div class='card-body'><h3 class='mb-4'>$title</h3>";
}

function footerHtml()
{
    echo "</div></div></div></body></html>";
}

function formatBytes($bytes, $decimals = 2)
{
    $size   = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . $size[$factor];
}

function githubApiRequest($url)
{
    if (!ini_get('allow_url_fopen')) {
        return [null, "allow_url_fopen is disabled in php.ini; enable it or ask your host to."];
    }

    $headers = [
        "User-Agent: install-script",
        "Accept: application/vnd.github.v3+json",
    ];
    $opts = [
        'http' => [
            'header'        => implode("\r\n", $headers) . "\r\n",
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
    ];
    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $reason = error_get_last()['message'] ?? 'unknown error';
        return [null, "Request to GitHub failed: $reason"];
    }

    return [$response, null];
}

function downloadReleaseAsset($downloadUrl, $targetFile)
{
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Agent: install-script"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return "Failed to download asset, HTTP $code";
    file_put_contents($targetFile, $data);
    return true;
}

if ($currentStep == 1) {
    headerHtml("Step 1: Server Requirements");
    $ok = true;

    echo "<ul class='list-group'>";
    echo "<li class='list-group-item'>Required PHP: " . REQUIRED_PHP_VERSION . "</li>";
    echo "<li class='list-group-item'>Current PHP: " . PHP_VERSION . "</li>";
    if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<')) {
        echo "<li class='list-group-item text-danger'>PHP version too old</li>";
        $ok = false;
    }

    foreach (REQUIRED_EXTENSIONS as $ext) {
        if (!extension_loaded($ext)) {
            echo "<li class='list-group-item text-danger'>Missing extension: $ext</li>";
            $ok = false;
        } else {
            echo "<li class='list-group-item text-success'>$ext loaded</li>";
        }
    }
    echo "</ul>";

    if ($ok) {
        echo "<div class='mt-4 text-success'>Server environment looks good.</div>";
        echo "<a href='?step=2' class='btn btn-primary mt-3'>Next Step</a>";
    } else {
        echo "<div class='mt-4 text-danger'>Fix the above issues to continue.</div>";
    }

    footerHtml();
    exit;
}

if ($currentStep == 2) {
    $error             = '';
    $done              = false;
    $releases          = [];
    $selectedReleaseId = $_POST['release'] ?? null;
    $selectedAssetId   = $_POST['asset'] ?? null;

    [$json, $requestFailure] = githubApiRequest(GITHUB_API);
    if ($requestFailure) {
        $error = $requestFailure;
    } else {
        $data = json_decode($json, true);
        if (is_array($data) && array_is_list($data)) {
            $releases = $data;
        } elseif (is_array($data) && isset($data['message'])) {
            $error = "GitHub API error: " . $data['message'];
        } else {
            $error = "Failed to parse GitHub releases (unexpected response: " . htmlspecialchars(substr((string) $json, 0, 200)) . ")";
        }
    }

    if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && $selectedAssetId && $selectedReleaseId) {
        $downloadUrl = null;
        foreach ($releases as $r) {
            if ($r['id'] == $selectedReleaseId) {
                foreach ($r['assets'] ?? [] as $a) {
                    if ($a['id'] == $selectedAssetId) {
                        $downloadUrl = $a['browser_download_url'];
                        break 2;
                    }
                }
            }
        }

        if (!$downloadUrl) {
            $error = "Could not resolve the selected asset's download URL.";
        } else {
            $tmpZip = tempnam(sys_get_temp_dir(), 'gh_') . '.zip';
            $result = downloadReleaseAsset($downloadUrl, $tmpZip);
            if ($result === true) {
                $zip = new ZipArchive();
                if ($zip->open($tmpZip) === true) {
                    $zip->extractTo($targetDir);
                    $zip->close();
                    unlink($tmpZip);
                    $done = true;
                } else {
                    $error = "Failed to open ZIP archive.";
                }
            } else {
                $error = $result;
            }
        }
    }

    headerHtml("Step 2: Download from GitHub");

    if ($error) echo "<div class='alert alert-danger'>$error</div>";
    elseif ($done) {
        echo "<div class='alert alert-success'>Extraction complete.</div>";
        echo "<a href='?step=3' class='btn btn-success mt-3'>Next Step</a>";
        footerHtml(); exit;
    }

    if (!$error && $releases && !$selectedReleaseId) {
        echo "<form method='post' class='mb-3'>
            <label for='release' class='form-label'>Select Release</label>
            <select name='release' id='release' class='form-select' required onchange='this.form.submit()'>
                <option value=''>-- Choose Release --</option>";
        foreach ($releases as $release) {
            echo "<option value='{$release['id']}'>" . htmlspecialchars($release['name'] ?? $release['tag_name']) . "</option>";
        }
        echo "</select>
            <noscript><button class='btn btn-primary mt-3'>Continue</button></noscript>
        </form>";
    } elseif (!$error && $selectedReleaseId) {
        $assets = [];
        foreach ($releases as $r) {
            if ($r['id'] == $selectedReleaseId) {
                $assets = $r['assets'] ?? [];
                break;
            }
        }

        if (!$assets) {
            echo "<div class='alert alert-warning'>No downloadable assets found in this release.</div>";
        } else {
            echo "<form method='post' class='mb-3'>
                <input type='hidden' name='release' value='" . htmlspecialchars($selectedReleaseId) . "'>
                <label for='asset' class='form-label'>Select Asset</label>
                <select name='asset' id='asset' class='form-select' required>";
            foreach ($assets as $a) {
                echo "<option value='{$a['id']}'>" . htmlspecialchars($a['name']) . " (" . formatBytes($a['size']) . ")</option>";
            }
            echo "</select>
                <button class='btn btn-success mt-3'>Download and Extract</button>
            </form>";
        }
    }

    footerHtml();
    exit;
}

if ($currentStep == 3) {
    headerHtml("Step 3: Finalize Installation");
    echo "<p>Installation successful. Redirecting to <code>database setup</code>...</p>";
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $installUrl = $basePath . '/index.php';

    echo '<script>setTimeout(() => { window.location.href = "' . $installUrl . '"; }, 1500);</script>';
    echo '<a href="' . $installUrl . '" class="btn btn-success mt-3">Go to Installer</a>';

    footerHtml();
    exit;
}
