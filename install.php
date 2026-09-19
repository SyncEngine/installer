<?php

const OWNER = 'SyncEngine';
const REPO  = 'SyncEngine';
const GITHUB_API = 'https://api.github.com/repos/' . OWNER . '/' . REPO . '/releases';

$currentStep = (int)($_GET['step'] ?? 1);
$targetDir   = realpath(__DIR__ . '/../') . '/';
$stateFile   = __DIR__ . '/install-state.json';

$expectedDirName = 'public';
if (basename(__DIR__) !== $expectedDirName) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head><body>
          <div style="margin:50px auto;max-width:600px;font-family:sans-serif;text-align:center;">
          <h2 style="color:red;">Error: install.php must be located inside the /public directory.</h2>
          <p>Please move <code>install.php</code> to the correct location and try again.</p>
          </div></body></html>';
    exit;
}

// ─── Inline State Manager ────────────────────────────────────────────────────

class InstallerState
{
    private const FILE = 'install-state.json';

    public static function read(string $path): array
    {
        if (!is_file($path)) {
            return ['step' => null, 'releaseId' => null, 'assetId' => null, 'tag' => null, 'downloaded' => false, 'releases' => []];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return ['step' => null, 'releaseId' => null, 'assetId' => null, 'tag' => null, 'downloaded' => false, 'releases' => []];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['step' => null, 'releaseId' => null, 'assetId' => null, 'tag' => null, 'downloaded' => false, 'releases' => []];
    }

    public static function write(string $path, array $state): void
    {
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function clear(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

// ─── Inline Requirements Validator ───────────────────────────────────────────

class RequirementsValidator
{
    public static function loadFromUrl(string $rawUrl): array
    {
        $content = self::fetchFile($rawUrl);
        if ($content === false) {
            throw new \RuntimeException('Failed to fetch requirements.json from URL.');
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON in requirements.json.');
        }
        return $data;
    }

    public static function loadFromPath(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('requirements.json not found at ' . $path);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Cannot read requirements.json.');
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public static function loadFromComposer(string $composerPath): array
    {
        if (!is_file($composerPath)) {
            throw new \RuntimeException('composer.json not found at ' . $composerPath);
        }
        $content = file_get_contents($composerPath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read composer.json.');
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        // Extract PHP version constraint
        $phpReq = $data['require']['php'] ?? '';
        preg_match('/>=?(\d+\.\d+)/', $phpReq, $matches);
        $minVersion = $matches[1] ?? null;

        // Dynamically extract extensions from composer.json
        $extensions = [];
        $composerExtensions = array_merge(
            $data['require'] ?? [],
            $data['require-dev'] ?? []
        );

        foreach ($composerExtensions as $package => $version) {
            if (strpos($package, 'ext-') === 0) {
                $extName = substr($package, 4);
                $extensions[] = ['name' => $extName, 'required' => true];
            }
        }

        return [
            'php' => [
                'version' => [
                    'min' => $minVersion,
                ],
                'extensions' => $extensions,
            ],
            '_meta' => [
                'source' => 'composer.json',
                'url'    => 'https://github.com/' . OWNER . '/' . REPO . '/blob/master/composer.json',
            ],
        ];
    }

    public static function validate(array $requirements, string $projectDir): array
    {
        $errors   = [];
        $warnings = [];
        $info     = [];

        // PHP version
        $phpReq = $requirements['php']['version'] ?? null;
        if ($phpReq) {
            $minVersion = $phpReq['min'] ?? null;
            $recVersion = $phpReq['recommended'] ?? null;
            if ($minVersion && version_compare(PHP_VERSION, $minVersion, '<')) {
                $errors[] = 'PHP version ' . PHP_VERSION . ' is below minimum required ' . $minVersion . '.';
            }
            if ($recVersion && version_compare(PHP_VERSION, $recVersion, '<')) {
                $warnings[] = 'PHP version ' . PHP_VERSION . ' is below recommended ' . $recVersion . '. Consider upgrading.';
            }
        }
        $info['phpVersion'] = PHP_VERSION;

        // Extensions
        $extensions = $requirements['php']['extensions'] ?? [];
        foreach ($extensions as $ext) {
            $name = $ext['name'] ?? '';
            $required = $ext['required'] ?? true;
            $isLoaded = extension_loaded($name);

            if (!$isLoaded && isset($ext['alternatives']) && is_array($ext['alternatives'])) {
                $anyLoaded = false;
                foreach ($ext['alternatives'] as $alt) {
                    if (extension_loaded($alt)) {
                        $anyLoaded = true;
                        break;
                    }
                }
                if (!$anyLoaded && $required) {
                    $errors[] = 'Required extension group not satisfied: ' . $name . ' (need one of: ' . implode(', ', $ext['alternatives']) . ')';
                } elseif (!$anyLoaded) {
                    $warnings[] = 'Recommended extension group not satisfied: ' . $name . ' (need one of: ' . implode(', ', $ext['alternatives']) . ')';
                }
            } elseif (!$isLoaded && $required) {
                $errors[] = 'Required PHP extension not loaded: ' . $name;
            } elseif (!$isLoaded) {
                $warnings[] = 'Recommended PHP extension not loaded: ' . $name;
            }
        }

        // INI settings
        $iniSettings = $requirements['php']['ini'] ?? [];
        foreach ($iniSettings as $key => $requiredValue) {
            $currentValue = ini_get($key);
            if ($currentValue === false) {
                $currentValue = 'Off';
            }

            if ($key === 'memory_limit') {
                $minMemory     = self::parseMemoryLimit((string)$requiredValue);
                $currentMemory = self::parseMemoryLimit((string)$currentValue);
                if ($currentMemory > 0 && $currentMemory < $minMemory) {
                    $errors[] = 'PHP memory_limit (' . $currentValue . ') is below minimum required. Minimum: ' . self::formatBytes($minMemory);
                } elseif ($currentMemory > 0 && $currentMemory < ($minMemory * 2)) {
                    $warnings[] = 'PHP memory_limit (' . $currentValue . ') is low. Consider increasing to at least ' . self::formatBytes($minMemory * 2);
                }
            } elseif ($key === 'max_execution_time') {
                $currentExec = (int)$currentValue;
                $requiredExec = (int)$requiredValue;
                if ($currentExec > 0 && $currentExec < $requiredExec) {
                    $warnings[] = 'PHP max_execution_time (' . $currentExec . 's) is below recommended ' . $requiredExec . 's.';
                }
            } elseif (is_bool($requiredValue)) {
                $currentBool = (bool)$currentValue;
                if ($currentBool !== $requiredValue) {
                    $warnings[] = 'PHP ' . $key . ' is set to ' . $currentValue . ', recommended ' . ($requiredValue ? 'On' : 'Off') . '.';
                }
            } else {
                if ((string)$currentValue !== (string)$requiredValue) {
                    $warnings[] = 'PHP ' . $key . ' is set to ' . $currentValue . ', recommended ' . $requiredValue . '.';
                }
            }
        }

        // Disk space
        $diskSpaceMb = $requirements['server']['disk_space_mb'] ?? null;
        if ($diskSpaceMb !== null) {
            $freeBytes = disk_free_space($projectDir);
            if ($freeBytes !== false) {
                $freeMB = (int)($freeBytes / 1024 / 1024);
                $info['diskFreeMB'] = $freeMB;
                if ($freeMB < $diskSpaceMb) {
                    $errors[] = 'Insufficient disk space. Need at least ' . $diskSpaceMb . 'MB free, found ' . $freeMB . 'MB.';
                } elseif ($freeMB < ($diskSpaceMb * 2)) {
                    $warnings[] = 'Low disk space: ' . $freeMB . 'MB free. Recommended: at least ' . $diskSpaceMb . 'MB.';
                }
            } else {
                $errors[] = 'Cannot determine available disk space.';
            }
        }

        // Web server info
        $info['webServer'] = self::detectWebServerType();

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
            'info'     => $info,
            'requirements' => $requirements,
        ];
    }

    public static function parseMemoryLimit(string $value): int
    {
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $value  = trim($value);
        $number = (int)$value;
        $lastChar = strtolower(substr($value, -1));
        switch ($lastChar) {
            case 'g': $number *= 1024;
            // no break
            case 'm': $number *= 1024;
            // no break
            case 'k': $number *= 1024;
        }
        return $number;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        elseif ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        elseif ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' bytes';
    }

    public static function detectWebServerType(): string
    {
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (stristr($serverSoftware, 'Apache') !== false) return 'Apache';
        elseif (stristr($serverSoftware, 'LiteSpeed') !== false) return 'LiteSpeed';
        elseif (stristr($serverSoftware, 'FrankenPHP') !== false) return 'FrankenPHP';
        elseif (stristr($serverSoftware, 'Nginx') !== false) return 'Nginx';
        elseif (stristr($serverSoftware, 'lighttpd') !== false) return 'lighttpd';
        elseif (stristr($serverSoftware, 'IIS') !== false) return 'Microsoft IIS';
        elseif (stristr($serverSoftware, 'Symfony Local Server') !== false) return 'Symfony Local Server - NOT FOR LIVE USAGE';
        return 'Not detected';
    }

    public static function fetchFile(string $url): ?string
    {
        return fetchRemote($url);
    }
}

// ─── HTTP Fetch Helper ──────────────────────────────────────────────────────

function fetchRemote(string $url): string
{
    if (extension_loaded('curl')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException('Request failed (curl): ' . $curlError);
        }
        return $response;
    }
    if (ini_get('allow_url_fopen')) {
        $response = @file_get_contents($url);
        if ($response === false) {
            throw new \RuntimeException('Request failed: ' . (error_get_last()['message'] ?? 'unknown error'));
        }
        return $response;
    }
    throw new \RuntimeException('Neither the curl extension nor allow_url_fopen is available.');
}


// ─── Inline GitHub Client ────────────────────────────────────────────────────

class GitHubClient
{
    public static function fetchReleases(): array
    {
        $url = GITHUB_API;
        $headers = [
            'User-Agent: install-script',
            'Accept: application/vnd.github.v3+json',
        ];

        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new \RuntimeException('Request to GitHub failed (curl): ' . $curlError);
            }
        } elseif (ini_get('allow_url_fopen')) {
            $opts = [
                'http' => [
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'timeout' => 15,
                    'ignore_errors' => true,
                ],
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new \RuntimeException('Request to GitHub failed: ' . (error_get_last()['message'] ?? 'unknown error'));
            }
        } else {
            throw new \RuntimeException('Neither the curl extension nor allow_url_fopen is available on this server.');
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !array_is_list($data)) {
            if (is_array($data) && isset($data['message'])) {
                throw new \RuntimeException('GitHub API error: ' . $data['message']);
            }
            throw new \RuntimeException('Failed to parse GitHub releases (unexpected response: ' . substr((string)$response, 0, 200) . ')');
        }
        return $data;
    }

    public static function fetchRawFile(string $tag, string $file): ?string
    {
        $url = 'https://raw.githubusercontent.com/' . OWNER . '/' . REPO . '/' . rawurlencode($tag) . '/' . ltrim($file, '/');
        try {
            if (extension_loaded('curl')) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($response === false || $httpCode !== 200) {
                    return null;
                }
            } else {
                $context = stream_context_create(['http' => ['ignore_errors' => true]]);
                $response = @file_get_contents($url, false, $context);
                if ($response === false) {
                    return null;
                }
                $headers = $http_response_header ?? [];
                foreach ($headers as $header) {
                    if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $header, $m)) {
                        if ((int)$m[1] !== 200) {
                            return null;
                        }
                        break;
                    }
                }
            }

            // Validate it's valid JSON (GitHub returns HTML error pages for 404s)
            json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $response;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function downloadAsset(array $asset, string $targetFile): string|null
    {
        $downloadUrl = $asset['browser_download_url'] ?? null;
        if (!$downloadUrl) {
            return 'No download URL found for asset.';
        }

        $ch = curl_init($downloadUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: install-script']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        // TLS verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $data === false) {
            return 'Failed to download asset, HTTP ' . $code . ($curlError ? ' (' . $curlError . ')' : '');
        }

        file_put_contents($targetFile, $data);
        return null; // success
    }

    public static function extractZip(string $zipPath, string $targetDir): string|null
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return 'Failed to open ZIP archive.';
        }

        // Find the content root (first directory that looks like a release root)
        $extracted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'SyncEngine-') === 0 || strpos($name, 'syncengine-') === 0) {
                $extracted = true;
                break;
            }
        }

        if ($extracted) {
            // Extract to a temp dir first, then find the content root
            $tempExtract = sys_get_temp_dir() . '/se_install_' . uniqid();
            @mkdir($tempExtract, 0755, true);
            $zip->extractTo($tempExtract);
            $zip->close();

            // Find and copy the actual content directory
            $items = scandir($tempExtract);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $fullPath = $tempExtract . '/' . $item;
                    if (is_dir($fullPath) && (file_exists($fullPath . '/src') || file_exists($fullPath . '/composer.json'))) {
                        self::copyDirectory($fullPath, $targetDir);
                        break;
                    }
                }
            }

            // Clean up temp
            self::deleteFolder($tempExtract);
        } else {
            $zip->extractTo($targetDir);
            $zip->close();
        }

        return null; // success
    }

    public static function getZipContents(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }

        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts = explode('/', $name);
            if (count($parts) > 1) {
                $topLevel = $parts[0];
                $contents[$topLevel] = true;
            }
        }
        $zip->close();
        return array_keys($contents);
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        @mkdir($destination, 0755, true);
        $directory = opendir($source);
        if ($directory === false) return;

        while (($file = readdir($directory)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $sourcePath      = $source . '/' . $file;
            $destinationPath = $destination . '/' . $file;
            if (is_dir($sourcePath)) {
                self::copyDirectory($sourcePath, $destinationPath);
            } else {
                @copy($sourcePath, $destinationPath);
            }
        }
        closedir($directory);
    }

    private static function deleteFolder(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::deleteFolder($path) : @unlink($path);
        }
        rmdir($dir);
    }
}

// ─── UI Helpers ──────────────────────────────────────────────────────────────

function headerHtml($title)
{
    echo '<!DOCTYPE html><html lang="en"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>' . htmlspecialchars($title) . '</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head><body class="bg-light">
    <div class="container py-5" style="max-width: 700px;">
    <div class="card"><div class="card-body"><h3 class="mb-4">' . htmlspecialchars($title) . '</h3>';
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

function renderRequirementsTable(array $result): void
{
    $reqs = $result['requirements'];

    // Show note if using composer.json fallback
    if (isset($reqs['_meta']['source']) && $reqs['_meta']['source'] === 'composer.json') {
        echo '<div class="alert alert-info mb-3">Using requirements from composer.json. For full validation, please check <a href="' . htmlspecialchars($reqs['_meta']['url']) . '" target="_blank">the SyncEngine repository</a>.</div>';
    }

    echo '<ul class="list-group mb-3">';
    echo '<li class="list-group-item"><strong>Web Server:</strong> ' . htmlspecialchars($result['info']['webServer'] ?? 'N/A') . '</li>';

    // Determine PHP version color based on validation result
    $phpClass = 'text-success';
    foreach ($result['errors'] ?? [] as $err) {
        if (stripos($err, 'PHP version') !== false) {
            $phpClass = 'text-danger';
            break;
        }
    }
    if ($phpClass === 'text-success') {
        foreach ($result['warnings'] ?? [] as $warn) {
            if (stripos($warn, 'PHP version') !== false) {
                $phpClass = 'text-warning';
                break;
            }
        }
    }

    echo '<li class="list-group-item"><strong>PHP Version:</strong> <span class="' . $phpClass . '">' . PHP_VERSION . '</span>';
    if (isset($reqs['php']['version']['min'])) {
        echo ' <span class="text-muted">(required: ' . htmlspecialchars($reqs['php']['version']['min']) . ')</span>';
    }
    if (isset($reqs['php']['version']['recommended'])) {
        echo ', recommended: ' . htmlspecialchars($reqs['php']['version']['recommended']);
    }
    echo '</li>';
    echo '</ul>';

    // Extensions table
    if (!empty($reqs['php']['extensions'])) {
        echo '<div class="card mb-3"><div class="card-header">PHP Extensions</div><div class="card-body">';
        echo '<div style="overflow-x: auto;">';
        echo '<table class="table table-striped table-sm mb-0" style="min-width: 300px; max-width: 100%;">';
        echo '<thead><tr><th class="text-start">Extension</th><th>Status</th></tr></thead><tbody>';
        foreach ($reqs['php']['extensions'] as $ext) {
            $name = $ext['name'] ?? '';
            $required = $ext['required'] ?? true;
            $isLoaded = extension_loaded($name);

            if (!$isLoaded && isset($ext['alternatives'])) {
                $anyLoaded = false;
                foreach ($ext['alternatives'] as $alt) {
                    if (extension_loaded($alt)) { $anyLoaded = true; break; }
                }
                if ($anyLoaded) {
                    $status = '<span class="text-success">Satisfied (alternative loaded)</span>';
                } elseif ($required) {
                    $status = '<span class="text-danger">Not satisfied (need one of: ' . implode(', ', $ext['alternatives']) . ')</span>';
                } else {
                    $status = '<span class="text-warning">Not satisfied (recommended)</span>';
                }
            } elseif ($isLoaded) {
                $status = '<span class="text-success">Loaded</span>';
            } elseif ($required) {
                $status = '<span class="text-danger">Required, not loaded</span>';
            } else {
                $status = '<span class="text-warning">Recommended, not loaded</span>';
            }

            echo '<tr><td class="text-start">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $name))) . '</td><td>' . $status . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div></div>';
    }

    // INI settings table
    if (!empty($reqs['php']['ini'])) {
        echo '<div class="card mb-3"><div class="card-header">PHP Configuration</div><div class="card-body">';
        echo '<div style="overflow-x: auto;">';
        echo '<table class="table table-striped table-sm mb-0" style="min-width: 300px; max-width: 100%;">';
        echo '<thead><tr><th class="text-start">Setting</th><th>Required</th><th>Current</th></tr></thead><tbody>';
        foreach ($reqs['php']['ini'] as $key => $requiredValue) {
            $currentValue = ini_get($key);
            if ($currentValue === false) $currentValue = 'Off';

            $match = true;
            if (is_bool($requiredValue)) {
                $match = (bool)$currentValue === $requiredValue;
                $displayRequired = $requiredValue ? 'On' : 'Off';
            } else {
                $match = (string)$currentValue === (string)$requiredValue;
                $displayRequired = $requiredValue;
            }

            echo '<tr><td class="text-start">' . htmlspecialchars($key) . '</td>';
            echo '<td>' . htmlspecialchars($displayRequired) . '</td>';
            echo '<td class="' . ($match ? 'text-success' : 'text-warning') . '">' . htmlspecialchars($currentValue) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div></div>';
    }

    // Disk space
    if (isset($reqs['server']['disk_space_mb'])) {
        $freeMB = $result['info']['diskFreeMB'] ?? 0;
        echo '<div class="card mb-3"><div class="card-header">Disk Space</div><div class="card-body">';
        echo '<table class="table table-sm mb-0" style="max-width: 100%;">';
        echo '<tr><td>Free space:</td><td class="' . ($freeMB >= $reqs['server']['disk_space_mb'] ? 'text-success' : 'text-danger') . '">' . formatBytes($freeMB * 1024 * 1024) . '</td></tr>';
        echo '<tr><td>Required:</td><td>' . $reqs['server']['disk_space_mb'] . ' MB</td></tr>';
        echo '</table></div></div>';
    }

    // Warnings
    if (!empty($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
            echo '<div class="alert alert-warning">' . htmlspecialchars($warning) . '</div>';
        }
    }
}

function renderDirectoryCheck(array $dirs, string $targetDir): void
{
    echo '<div class="card mb-3"><div class="card-header">Directory Checks</div><div class="card-body">';
    echo '<div style="overflow-x: auto;">';
    echo '<table class="table table-striped table-sm mb-0" style="min-width: 400px; max-width: 100%;">';
    echo '<thead><tr><th class="text-start">Directory</th><th>Status</th></tr></thead><tbody>';

    foreach ($dirs as $dir) {
        if ($dir === '.') continue; // skip root
        $fullPath = $targetDir . '/' . $dir;
        $parentDir = dirname($fullPath);
        $canCreate = is_dir($parentDir) && is_writable($parentDir);

        if (!is_dir($fullPath)) {
            if ($canCreate) {
                echo '<tr><td class="text-start" style="word-break: break-all;">' . htmlspecialchars($dir) . '/</td><td class="text-success">Can create</td></tr>';
            } else {
                echo '<tr><td class="text-start" style="word-break: break-all;">' . htmlspecialchars($dir) . '/</td><td class="text-danger">Cannot create (parent not writable)</td></tr>';
            }
        } elseif (!is_writable($fullPath)) {
            echo '<tr><td class="text-start" style="word-break: break-all;">' . htmlspecialchars($dir) . '/</td><td class="text-danger">Not writable</td></tr>';
        } else {
            // Check if empty (except public which already exists)
            $items = scandir($fullPath);
            $nonDotFiles = array_filter($items, fn($f) => $f !== '.' && $f !== '..');
            if (!empty($nonDotFiles)) {
                echo '<tr><td class="text-start" style="word-break: break-all;">' . htmlspecialchars($dir) . '/</td><td class="text-warning">Not empty (' . count($nonDotFiles) . ' items)</td></tr>';
            } else {
                echo '<tr><td class="text-start" style="word-break: break-all;">' . htmlspecialchars($dir) . '/</td><td class="text-success">Writable and empty</td></tr>';
            }
        }
    }

    echo '</tbody></table></div></div>';
}

// ─── Step 1: Version Selection ──────────────────────────────────────────────

if ($currentStep == 1) {
    headerHtml('Step 1: Select Version');
    $error = '';

    // Load state (cached releases list)
    $state = InstallerState::read($stateFile);
    $releases = $state['releases'] ?? [];

    // Check if we need to refresh releases
    if ((isset($_GET['refresh']) || isset($_POST['refresh'])) && !empty($releases)) {
        $releases = [];
        InstallerState::write($stateFile, ['releases' => [], 'releaseId' => null, 'tag' => null, 'downloaded' => false]);
    }

    if (empty($releases) && empty($_POST['manual'])) {
        try {
            $releases = GitHubClient::fetchReleases();
            // Cache releases in state file
            InstallerState::write($stateFile, ['releases' => $releases, 'releaseId' => null, 'tag' => null, 'downloaded' => false]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($error) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
        echo '<p>GitHub is unreachable. You can:</p>';
        echo '<ol>';
        echo '<li><a href="?step=1&manual=1" class="btn btn-outline-primary">Continue manually</a></li>';
        echo '<li><a href="' . GITHUB_API . '" target="_blank" class="btn btn-outline-secondary">View releases on GitHub</a> and select a version below.</li>';
        echo '</ol>';
    }

    if (!empty($_POST['manual']) || isset($_GET['manual'])) {
        // Manual mode: user picks from a list they can verify on GitHub
        $tag = $_POST['tag'] ?? '';
        if ($tag) {
            InstallerState::write($stateFile, ['releases' => $releases, 'releaseId' => null, 'tag' => $tag, 'downloaded' => false]);
            header('Location: ?step=2');
            exit;
        }

        echo '<form method="post">';
        echo '<input type="hidden" name="manual" value="1">';
        echo '<label for="tag" class="form-label">Enter release tag (e.g. 0.1.0-beta.6):</label>';
        echo '<input type="text" name="tag" id="tag" class="form-control mb-2" placeholder="0.1.0-beta.6" required value="' . htmlspecialchars($tag) . '">';
        echo '<p class="text-muted small">Find the tag at <a href="' . GITHUB_API . '" target="_blank">' . GITHUB_API . '</a></p>';
        echo '<div class="mt-2"><button class="btn btn-primary">Continue</button></div>';
        echo '</form>';
    } else {
        if ($releases) {
            $hasSelection = !empty($state['releaseId']);
            echo '<form method="post" class="mb-3"><label for="release" class="form-label">Select Release</label>';
            echo '<select name="release" id="release" class="form-select" required>';
            echo '<option value="">-- Choose Release --</option>';
            foreach ($releases as $release) {
                $tagName = $release['tag_name'] ?? $release['name'] ?? '';
                $selected = ($state['releaseId'] && (string)$release['id'] === (string)$state['releaseId']) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($release['id']) . '"' . $selected . '>' . htmlspecialchars($tagName) . '</option>';
            }
            echo '</select>';
            echo '<div class="mt-3">';
            echo '<button type="submit" class="btn btn-primary">Continue</button>';
            echo ' <a href="?step=1&refresh=1" class="btn btn-outline-secondary">Refresh</a>';
            echo '</div>';
            echo '</form>';

            // Handle the POST selection
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['release'])) {
                foreach ($releases as $r) {
                    if ((string)$r['id'] === (string)$_POST['release']) {
                        $tagName = $r['tag_name'] ?? $r['name'] ?? '';
                        InstallerState::write($stateFile, ['releases' => $releases, 'releaseId' => (string)$r['id'], 'tag' => $tagName, 'downloaded' => false]);
                        header('Location: ?step=2');
                        exit;
                    }
                }
            }
        }
    }

    footerHtml();
    exit;
}

// ─── Step 2: Requirements Check ──────────────────────────────────────────────

if ($currentStep == 2) {
    $state = InstallerState::read($stateFile);
    $tag = $state['tag'] ?? null;

    if (!$tag) {
        header('Location: ?step=1');
        exit;
    }

    headerHtml('Step 2: Requirements Check');

    $error = '';
    $reqs = [];
    $validationResult = null;

    try {
        $rawContent = GitHubClient::fetchRawFile($tag, 'requirements.json');
        if ($rawContent !== null) {
            $reqs = json_decode($rawContent, true);
            if (is_array($reqs)) {
                $validationResult = RequirementsValidator::validate($reqs, $targetDir);
            } else {
                $error = 'Invalid JSON in requirements.json.';
            }
        } else {
            // Fallback: try composer.json from the same tag
            $composerRaw = GitHubClient::fetchRawFile($tag, 'composer.json');
            if ($composerRaw !== null) {
                $composerData = json_decode($composerRaw, true);
                if (is_array($composerData)) {
                    $reqs = RequirementsValidator::loadFromComposer('/tmp/composer.json');
                    file_put_contents('/tmp/composer.json', $composerRaw);
                    $validationResult = RequirementsValidator::validate($reqs, $targetDir);
                    unlink('/tmp/composer.json');
                } else {
                    $error = 'Invalid JSON in composer.json.';
                }
            }

            if (!$validationResult && !$error) {
                $error = 'Could not fetch requirements.json or composer.json from tag "' . htmlspecialchars($tag) . '". The file may not exist in this release.';
            }
        }
    } catch (\Throwable $e) {
        // Fallback: try loading from local if it exists
        $localPath = $targetDir . 'requirements.json';
        if (is_file($localPath)) {
            $reqs = RequirementsValidator::loadFromPath($localPath);
            $validationResult = RequirementsValidator::validate($reqs, $targetDir);
        } else {
            // Try composer.json from local
            $localComposer = $targetDir . 'composer.json';
            if (is_file($localComposer)) {
                try {
                    $reqs = RequirementsValidator::loadFromComposer($localComposer);
                    $validationResult = RequirementsValidator::validate($reqs, $targetDir);
                } catch (\Throwable $e2) {
                    $error = 'Error fetching requirements: ' . $e->getMessage();
                }
            } else {
                // Try composer.json from tag as last resort
                try {
                    $composerRaw = GitHubClient::fetchRawFile($tag, 'composer.json');
                    if ($composerRaw !== null) {
                        file_put_contents('/tmp/composer.json', $composerRaw);
                        $reqs = RequirementsValidator::loadFromComposer('/tmp/composer.json');
                        $validationResult = RequirementsValidator::validate($reqs, $targetDir);
                        unlink('/tmp/composer.json');
                    } else {
                        $error = 'Error fetching requirements: ' . $e->getMessage();
                    }
                } catch (\Throwable $e3) {
                    $error = 'Error fetching requirements: ' . $e->getMessage();
                }
            }
        }
    }

    if ($error) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
        echo '<p>You can still proceed to download, but the requirements check was skipped.</p>';
        echo '<div class="mt-2">';
        echo '<a href="?step=3" class="btn btn-primary">Continue to Download</a> ';
        echo '<a href="?step=1" class="btn btn-outline-secondary">Cancel</a>';
        echo '</div>';
    } elseif ($validationResult && !$validationResult['valid']) {
        renderRequirementsTable($validationResult);
        echo '<div class="mt-3">';
        echo '<a href="?step=3" class="btn btn-warning">Proceed Anyway</a> ';
        echo '<a href="?step=1" class="btn btn-outline-secondary">Cancel</a>';
        echo '</div>';
    } elseif ($validationResult) {
        renderRequirementsTable($validationResult);
        echo '<div class="mt-3 text-success">Server environment looks good.</div>';
        echo '<div class="mt-2">';
        echo '<a href="?step=3" class="btn btn-primary">Continue to Download</a> ';
        echo '<a href="?step=1" class="btn btn-outline-secondary">Cancel</a>';
        echo '</div>';
    }

    footerHtml();
    exit;
}

// ─── Step 3: Download & Extract ──────────────────────────────────────────────

if ($currentStep == 3) {
    $state = InstallerState::read($stateFile);
    $tag = $state['tag'] ?? null;

    if (!$tag) {
        header('Location: ?step=1');
        exit;
    }

    headerHtml('Step 3: Download & Extract');
    echo '<p class="text-muted">Selected version: <strong>' . htmlspecialchars($tag) . '</strong></p>';

    // Load cached releases from state
    $releases = $state['releases'] ?? [];
    $releaseId = $_GET['release'] ?? $state['releaseId'] ?? null;
    $error = '';

    if (empty($releases)) {
        try {
            $releases = GitHubClient::fetchReleases();
            InstallerState::write($stateFile, ['releases' => $releases, 'releaseId' => $releaseId, 'tag' => $tag, 'downloaded' => false]);
        } catch (\Throwable $e) {
            $error = 'Failed to fetch releases: ' . $e->getMessage();
        }
    }

    // Find the release.zip asset for selected release
    $downloadUrl = null;
    if ($releaseId) {
        foreach ($releases as $r) {
            if ((string)$r['id'] === (string)$releaseId) {
                foreach ($r['assets'] ?? [] as $a) {
                    if (stripos($a['name'], 'release') !== false && stripos($a['name'], '.zip') !== false) {
                        $downloadUrl = $a['browser_download_url'];
                        break 2;
                    }
                }
            }
        }
    }

    if ($downloadUrl) {
        // Download and extract in one step
        $tmpZip = tempnam(sys_get_temp_dir(), 'se_') . '.zip';
        echo '<p>Downloading release...</p>';
        $downloadError = GitHubClient::downloadAsset(['browser_download_url' => $downloadUrl], $tmpZip);

        if ($downloadError === null) {
            // Inspect contents before extracting
            $zipContents = GitHubClient::getZipContents($tmpZip);

            if (!empty($zipContents)) {
                renderDirectoryCheck($zipContents, $targetDir);

                // Check for issues
                $hasCriticalErrors = false;
                foreach ($zipContents as $dir) {
                    if ($dir === '.') continue;
                    $fullPath = $targetDir . '/' . $dir;
                    if (is_dir($fullPath) && !is_writable($fullPath)) {
                        $hasCriticalErrors = true;
                    }
                }

                echo '<div class="card-footer mt-3">';
                echo '<form method="post" class="mb-0">';
                echo '<input type="hidden" name="zip" value="' . htmlspecialchars($tmpZip) . '">';
                echo '<input type="hidden" name="tag" value="' . htmlspecialchars($tag) . '">';
                if ($hasCriticalErrors) {
                    echo '<div class="alert alert-warning mb-2">Some directories have issues. You can try to proceed anyway.</div>';
                }
                echo '<button class="btn btn-success me-2">Install</button>';
                echo '<a href="?step=1" class="btn btn-outline-secondary">Cancel</a>';
                echo '</form></div>';
            } else {
                // No subdirectories, extract directly
                $result = GitHubClient::extractZip($tmpZip, $targetDir);
                if ($result === null) {
                    unlink($tmpZip);
                    echo '<div class="alert alert-success">Extraction complete.</div>';
                    echo '<div class="mt-3"><a href="?step=4" class="btn btn-success">Continue</a></div>';
                } else {
                    echo '<div class="alert alert-danger">Extraction failed: ' . htmlspecialchars($result) . '</div>';
                }
            }
        } else {
            echo '<div class="alert alert-danger">Download failed: ' . htmlspecialchars($downloadError) . '</div>';
        }
    } elseif ($releaseId && empty($releases)) {
        echo '<div class="alert alert-warning">No release.zip asset found in this release.</div>';
        echo '<div class="mt-2"><a href="?step=1" class="btn btn-outline-secondary">Choose a different version</a></div>';
    }

    if ($error) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    }

    // Handle extraction POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['zip'])) {
        $tmpZip = $_POST['zip'];
        $tag = $_POST['tag'] ?? null;

        echo '<p>Extracting...</p>';
        $result = GitHubClient::extractZip($tmpZip, $targetDir);

        if ($result === null) {
            unlink($tmpZip);
            echo '<div class="alert alert-success">Extraction complete.</div>';
            echo '<div class="mt-3"><a href="?step=4" class="btn btn-success">Continue</a></div>';
        } else {
            echo '<div class="alert alert-danger">Extraction failed: ' . htmlspecialchars($result) . '</div>';
            echo '<div class="mt-2"><a href="?step=1" class="btn btn-outline-secondary">Cancel</a></div>';
        }
    }

    footerHtml();
    exit;
}

// ─── Step 4: Handoff to SyncEngine Installer ────────────────────────────────────

if ($currentStep == 4) {
    headerHtml('Installation Complete');
    echo '<p>SyncEngine has been extracted. Redirecting to the SyncEngine installer for database setup and final configuration...</p>';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $installUrl = $basePath . '/index.php';

    echo '<script>setTimeout(() => { window.location.href = "' . htmlspecialchars($installUrl) . '"; }, 1500);</script>';
    echo '<a href="' . htmlspecialchars($installUrl) . '" class="btn btn-success mt-3">Go to SyncEngine Installer</a>';

    footerHtml();
    exit;
}

// ─── Fallback: redirect to step 1 ────────────────────────────────────────────

header('Location: ?step=1');
exit;
