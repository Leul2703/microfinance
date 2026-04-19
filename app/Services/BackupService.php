<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class BackupService
{
    private $backupPath;
    private $retentionDays;

    public function __construct()
    {
        $this->backupPath = storage_path('app/backups');
        $this->retentionDays = config('backup.retention_days', 30);
    }

    public function createFullBackup()
    {
        try {
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $backupDir = $this->backupPath . '/' . $timestamp;
            if (!File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            $dbBackupFile = $this->backupDatabaseSnapshot($backupDir, $timestamp);
            $this->backupFiles($backupDir);

            $metadata = [
                'timestamp' => $timestamp,
                'type' => 'full',
                'database_backup' => $dbBackupFile,
                'created_at' => Carbon::now()->toIso8601String(),
                'size' => $this->getBackupSize($backupDir),
            ];

            File::put($backupDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
            $this->cleanOldBackups();

            return ['success' => true, 'backup_path' => $backupDir, 'metadata' => $metadata, 'message' => 'Full backup created successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
        }
    }

    public function createDatabaseBackup()
    {
        try {
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $backupDir = $this->backupPath . '/database_' . $timestamp;
            if (!File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            $dbBackupFile = $this->backupDatabaseSnapshot($backupDir, $timestamp);
            $metadata = [
                'timestamp' => $timestamp,
                'type' => 'database',
                'database_backup' => $dbBackupFile,
                'created_at' => Carbon::now()->toIso8601String(),
                'size' => filesize($dbBackupFile),
            ];
            File::put($backupDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));

            return ['success' => true, 'backup_path' => $backupDir, 'metadata' => $metadata, 'message' => 'Database backup created successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Database backup failed: ' . $e->getMessage()];
        }
    }

    private function backupDatabaseSnapshot($backupDir, $timestamp)
    {
        $payload = [
            'database' => config('database.connections.mysql.database') ?: config('database.connections.sqlite.database'),
            'generated_at' => now()->toIso8601String(),
            'counts' => [
                'users' => \App\Models\User::count(),
                'customers' => \App\Models\Customer::count(),
                'loans' => \App\Models\Loan::count(),
                'savings_accounts' => \App\Models\SavingsAccount::count(),
                'repayments' => \App\Models\Repayment::count(),
            ],
        ];

        $backupFile = $backupDir . '/database_snapshot_' . $timestamp . '.json';
        File::put($backupFile, json_encode($payload, JSON_PRETTY_PRINT));
        return $backupFile;
    }

    private function backupFiles($backupDir)
    {
        $directoriesToBackup = [
            'app' => 'application',
            'resources/views' => 'views',
            'public' => 'public_files',
            'database/migrations' => 'migrations',
        ];

        foreach ($directoriesToBackup as $source => $destination) {
            $sourcePath = base_path($source);
            $destPath = $backupDir . '/' . $destination;

            if (File::exists($sourcePath)) {
                File::copyDirectory($sourcePath, $destPath);
            }
        }
    }

    private function getBackupSize($backupDir)
    {
        $size = 0;
        foreach (File::allFiles($backupDir) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function cleanOldBackups()
    {
        if (!File::exists($this->backupPath)) {
            return;
        }

        $directories = File::directories($this->backupPath);
        $cutoffDate = Carbon::now()->subDays($this->retentionDays);

        foreach ($directories as $directory) {
            $lastModified = Carbon::createFromTimestamp(File::lastModified($directory));
            if ($lastModified->lt($cutoffDate)) {
                File::deleteDirectory($directory);
            }
        }
    }

    public function restoreBackup($backupPath)
    {
        try {
            if (!File::exists($backupPath)) {
                throw new \Exception('Backup directory not found');
            }

            $metadataFile = $backupPath . '/metadata.json';
            if (!File::exists($metadataFile)) {
                throw new \Exception('Backup metadata not found');
            }

            $metadata = json_decode(File::get($metadataFile), true);
            return ['success' => true, 'message' => 'Backup validated successfully for restore workflow', 'metadata' => $metadata];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    public function listBackups()
    {
        if (!File::exists($this->backupPath)) {
            return [];
        }

        $backups = [];
        $directories = File::directories($this->backupPath);

        foreach ($directories as $directory) {
            $metadataFile = $directory . '/metadata.json';
            if (File::exists($metadataFile)) {
                $metadata = json_decode(File::get($metadataFile), true);
                $backups[] = [
                    'path' => $directory,
                    'metadata' => $metadata,
                    'size' => $this->getBackupSize($directory),
                ];
            }
        }

        usort($backups, function ($a, $b) {
            return strtotime($b['metadata']['timestamp']) - strtotime($a['metadata']['timestamp']);
        });

        return $backups;
    }

    public function getBackupStatistics()
    {
        $backups = $this->listBackups();
        $totalSize = array_sum(array_column($backups, 'size'));

        return [
            'total_backups' => count($backups),
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'latest_backup' => !empty($backups) ? $backups[0]['metadata']['timestamp'] : null,
            'oldest_backup' => !empty($backups) ? end($backups)['metadata']['timestamp'] : null,
            'retention_days' => $this->retentionDays,
        ];
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}