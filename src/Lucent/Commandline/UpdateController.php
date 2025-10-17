<?php

namespace Lucent\Commandline;

use Exception;
use Lucent\Commandline\Components\ProgressBar;
use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;
use Lucent\Http\HttpClient;
use Lucent\StaticAnalysis\DependencyAnalyser;
use Phar;

class UpdateController
{
    private string $downloadPath;

    public function __construct()
    {
        $this->downloadPath = DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . "downloads" . DIRECTORY_SEPARATOR;
    }

    public function check(array $options = []): string
    {

        $app = new Folder("/App");

        if (!$app->exists()) {
            $app->create();
        }

        if (!isset($options["file"])) {
            // Directly get version from PHAR metadata
            $currentVersion = App::getLucentVersion();

            if (!$currentVersion) {
                return "Unable to determine current version.";
            }

            try {
                $client = new HttpClient('https://api.github.com');
                $response = $client->get('/repos/blueprintau/Lucent/releases/latest');

                if (!$response->successful()) {
                    return "Unable to lookup the latest version.";
                }
                $latestRelease = $response->json();
                $latestVersion = $latestRelease['tag_name'];

                if (!(version_compare($currentVersion, $latestVersion, '<') || str_contains($currentVersion, "local"))) {
                    return "You're running the latest version of Lucent ({$currentVersion}). 👍" . PHP_EOL;
                }

                $downloaded = $this->downloadLatest();

                if ($downloaded === null) {
                    return "Failed to download latest version.\n";
                }

                // Ensure the packages directory exists
                $packageFolder = new Folder("/packages");

                $downloaded->copy($downloaded->getName(), $packageFolder);

                if (!$downloaded->delete()) {
                    return "Failed to delete temp download.\n";
                }
                $output = [];
                exec("cd " . FileSystem::rootPath() . "/packages && php " . $downloaded->getName() . " update check --file=" . $downloaded->getName(), $output);
                $lines = "";
                foreach ($output as $line) {
                    $lines .= $line . PHP_EOL;
                }

                $downloaded->delete();

                return "Running update dependency check: \n" . $lines . "\n";
            } catch (Exception $e) {
                return "Unable to check for updates: {$e->getMessage()}\n";
            }
        }

        $analyser = new DependencyAnalyser();

        $analyser->parseFiles($app->search()->onlyFiles()->extension("php")->recursive()->collect());

        $analyser->printCompatibilityCheck();

        return "Compatibility check completed.\n";
    }

    public function install(): string
    {
        $currentVersion = App::getLucentVersion();
        $currentPharPath = Phar::running(false);

        $app = new Folder("/App");

        if (!$app->exists()) {
            $app->create();
        }

        if (!$currentVersion) {
            return "Unable to determine current version.";
        }

        try {
            $client = new HttpClient();
            $response = $client->get('https://api.github.com/repos/blueprintau/Lucent/releases/latest');

            if ($response->successful()) {
                $latestRelease = $response->json();
                $latestVersion = $latestRelease['tag_name'];

                if (version_compare($currentVersion, $latestVersion, '<') || str_contains($currentVersion, "local")) {
                    // Prepare paths
                    $targetPharPath = dirname($currentPharPath) . DIRECTORY_SEPARATOR . "lucent-{$latestVersion}.phar";
                    $backupPharPath = dirname($currentPharPath) . DIRECTORY_SEPARATOR . "lucent-{$currentVersion}.phar.old";

                    $phar = new File("/packages/lucent.phar");

                    if (!$phar->exists()) {
                        return "Failed to locate lucent.phar.";
                    }

                    $packagesFolder = new Folder("/packages");

                    $backup = $phar->copy("lucent-{$currentVersion}.phar.old", $packagesFolder);

                    if (!$backup->exists()) {
                        return "Failed to backup current version of lucent.";
                    }

                    $downloaded = $this->downloadLatest();

                    $copy = $downloaded->copy($downloaded->getName(), $packagesFolder);

                    if (!$copy->exists()) {
                        return "Failed to move download into packages folder...\n";
                    }

                    if (!$phar->delete()) {
                        return "Failed to replace lucent.phar package...\n";
                    }

                    if (!$copy->rename("lucent.phar")) {
                        return "Failed to replace lucent.phar package...\n";
                    }

                    if (!$downloaded->delete()) {
                        return "Failed to delete temp download...\n";
                    }

                    $output = [];
                    exec("cd " . FileSystem::rootPath() . "/packages && php lucent.phar update check --file=" . $downloaded->path, $output);
                    // Join the output array into a string with line breaks
                    $outputString = implode(PHP_EOL, $output);

                    return sprintf(
                        "Successfully updated Lucent! 🎉" . PHP_EOL .
                        "Old version: %s\n" .
                        "New version: %s\n\n" .
                        "Backup of old version saved at: %s\n\n" .
                        "Compatibility Check:\n%s",
                        $currentVersion,
                        $latestVersion,
                        $backupPharPath,
                        $outputString
                    );
                } else {
                    return "You're running the latest version of Lucent ({$currentVersion}). 👍" . PHP_EOL;
                }
            }

            return "Unable to check for updates. Please check your internet connection.";
        } catch (Exception $e) {
            // Clean up temporary files if they exist
            if (isset($downloadedFilePath) && file_exists($downloadedFilePath)) {
                unlink($downloadedFilePath);
            }
            if (isset($targetPharPath) && file_exists($targetPharPath)) {
                unlink($targetPharPath);
            }
            return "Update check failed: " . $e->getMessage();
        }
    }

    public function rollback(): string
    {
        $install_location = RUNNING_LOCATION . 'packages' . DIRECTORY_SEPARATOR;

        // Find backup PHARs
        $backupFiles = glob($install_location . 'lucent-*.phar.old');

        if (empty($backupFiles)) {
            return "No backup versions found to roll back to.";
        }

        // Sort backups to get the most recent
        usort($backupFiles, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $backupPharPath = $backupFiles[0];

        // Extract version from backup filename
        preg_match('/lucent-(.+)\.phar\.old/', basename($backupPharPath), $matches);
        $backupVersion = $matches[1] ?? 'unknown';


        $currentPharPath = $install_location . "lucent.phar";

        // Create a safety backup of the current version
        $safetyBackupPath = $install_location . "lucent-" . App::getLucentVersion() . ".phar.rollback.old";

        if (!copy($currentPharPath, $safetyBackupPath)) {
            return "Failed to create safety backup of current version.";
        }

        // Replace current PHAR with backup
        if (!copy($backupPharPath, $currentPharPath)) {
            return "Failed to restore backup PHAR. Your current version is backed up at: " . $safetyBackupPath;
        }

        // Verify the restore worked
        if (!file_exists($currentPharPath)) {
            return "Critical: Rollback failed - lucent.phar missing after restore! Backup at: " . $safetyBackupPath;
        }

        // Clear stat cache so file checks are fresh
        clearstatcache(true);

        // Remove the used backup file (non-critical if this fails)
        if (!unlink($backupPharPath)) {
            error_log("Warning: Failed to delete used backup file: " . $backupPharPath);
        }

        return sprintf(
            "Rolled back successfully! 🔙\n" .
            "Reverted to version: %s\n\n" .
            "Current version backed up at: %s\n" .
            "Used backup: %s\n\n" .
            "NOTE: Restart your application to load the rolled back version.",
            $backupVersion,
            $safetyBackupPath,
            basename($backupPharPath)
        );
    }

    private function downloadLatest(): ?File
    {
        $currentVersion = App::getLucentVersion();

        $client = new HttpClient();
        $client->withTimeout(120); // Increase timeout to 2 minutes

        $response = $client->get('https://api.github.com/repos/blueprintau/Lucent/releases/latest');

        if ($response->successful()) {
            $latestRelease = $response->json();
            $latestVersion = $latestRelease['tag_name'];

            if (version_compare($currentVersion, $latestVersion, '<') || str_contains($currentVersion, "local")) {
                // Prepare paths
                $downloadUrl = $latestRelease['assets'][0]['browser_download_url'];
                $tempFileName = "lucent-{$latestVersion}.phar";

                echo "Downloading Lucent {$latestVersion}..." . PHP_EOL;

                // Initialize progress bar with an estimated size
                // We'll let the download method handle getting the actual size
                $estimatedSize = 5 * 1024 * 1024; // 5MB estimate
                $progress = new ProgressBar($estimatedSize);
                $progress->setFormat('[{bar}] {percent}% - {eta} remaining');
                $progress->setBarCharacters(['█', '░']);
                $progress->setUpdateInterval(0.1);

                // Create progress callback that updates the progress and adjusts total if needed
                $progressCallback = function ($downloadedBytes, $totalBytes) use ($progress) {
                    $progress->update($downloadedBytes);
                };

                // Download new PHAR using progress callback
                $downloadResponse = $client->download(
                    $downloadUrl,
                    $tempFileName,
                    $progressCallback
                );

                // Complete the progress bar
                $progress->finish();

                $downloadedFilePath = $tempFileName;

                if (file_exists($this->downloadPath . $downloadedFilePath) || $downloadResponse->successful()) {
                    return new File($this->downloadPath . $downloadedFilePath);
                }
            }
        }

        return null;
    }


}
