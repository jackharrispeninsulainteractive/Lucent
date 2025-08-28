<?php

namespace Lucent\Logging;

use Lucent\Facades\FileSystem;

class Channel
{
    private string $channel;
    private string $driver;
    private string $path;
    private bool $useColors;

    // Simplified color scheme to start with
    private static array $levelColors = [
        'emergency' => "\033[1;37;41m", // Bold white on red
        'alert' => "\033[1;31m",    // Bold red
        'critical' => "\033[0;31m",    // Red
        'error' => "\033[0;31m",    // Red
        'warning' => "\033[0;33m",    // Yellow
        'notice' => "\033[0;36m",    // Cyan
        'info' => "\033[0;32m",    // Green
        'debug' => "\033[0;37m"     // White
    ];

    public function __construct(string $channel, string $driver = 'local_file', string $path = '', ?bool $useColors)
    {
        $this->channel = $channel;
        $this->driver = $driver;
        $this->path = $path;
        // Very simple check if we are in a CLI environment if useColors is not set.
        $this->useColors = $useColors ?? PHP_SAPI === 'cli';
    }

    private function highlightSql(string $message): string
    {
        if (!preg_match('/(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)/i', $message)) {
            return $message;
        }

        // SQL Keywords
        $message = preg_replace(
            '/(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TABLE|FROM|WHERE|AND|OR|JOIN|GROUP BY|ORDER BY|LIMIT|OFFSET|VALUES|INTO|SET|FOREIGN KEY|REFERENCES|PRIMARY KEY)/i',
            "\033[36m$1\033[0m",
            $message
        );

        // Identifiers (quoted names)
        $message = preg_replace(
            '/`([^`]+)`/',
            "\033[33m`$1`\033[0m",
            $message
        );

        // String literals
        $message = preg_replace(
            '/\'([^\']+)\'/',
            "\033[32m'$1'\033[0m",
            $message
        );

        // Numbers
        $message = preg_replace(
            '/\b(\d+)\b/',
            "\033[35m$1\033[0m",
            $message
        );

        return $message;
    }

    private function formatMessage(string $level, string $message): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);

        if ($this->useColors) {
            $levelColor = $this->levelColors[$level] ?? "\033[0m";
            $formattedMessage = $this->highlightSql($message);
            return sprintf(
                "[%s] %s%s\033[0m | %s | %s\n",
                $timestamp,
                $levelColor,
                $levelUpper,
                $this->channel,
                $formattedMessage
            );
        }

        return sprintf(
            "[%s] %s | %s | %s\n",
            $timestamp,
            $levelUpper,
            $this->channel,
            $message
        );
    }

    private function write(string $level, string $message): void
    {
        $formattedMessage = $this->formatMessage($level, $message);

        if ($this->driver === 'local_file') {
            // Create logs directory if it doesn't exist
            $logDir = FileSystem::rootPath() . DIRECTORY_SEPARATOR . "logs";
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $logPath = $logDir . DIRECTORY_SEPARATOR . $this->path;

            // Try to open file and handle any errors
            $file = @fopen($logPath, "a");
            if ($file === false) {
                error_log("Failed to open log file: " . $logPath);
                return;
            }

            // Strip ANSI color codes for file logging
            fwrite($file, preg_replace('/\033\[[0-9;]*m/', '', $formattedMessage));
            fclose($file);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, $formattedMessage);
        }
    }

    // PSR-3 log levels
    public function emergency(string $message): void
    {
        $this->write('emergency', $message);
    }

    public function alert(string $message): void
    {
        $this->write('alert', $message);
    }

    public function critical(string $message): void
    {
        $this->write('critical', $message);
    }

    public function error(string $message): void
    {
        $this->write('error', $message);
    }

    public function warning(string $message): void
    {
        $this->write('warning', $message);
    }

    public function notice(string $message): void
    {
        $this->write('notice', $message);
    }

    public function info(string $message): void
    {
        $this->write('info', $message);
    }

    public function debug(string $message): void
    {
        $this->write('debug', $message);
    }
}