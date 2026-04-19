<?php

namespace App\Services;

use App\Models\User;
use App\Models\Repayment;
use App\Models\SavingsTransaction;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class UserDataArchivalService
{
    private $archivePath;

    public function __construct()
    {
        $this->archivePath = storage_path('app/archives');
    }

    public function archiveUserData($userId, $archiveReason = null)
    {
        try {
            $user = User::with(['customer', 'customer.loans', 'customer.savingsAccounts'])->findOrFail($userId);

            $archiveTimestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $archiveDir = $this->archivePath . '/user_' . $userId . '_' . $archiveTimestamp;

            if (!File::exists($archiveDir)) {
                File::makeDirectory($archiveDir, 0755, true);
            }

            $userData = $user->toArray();
            unset($userData['password'], $userData['remember_token']);
            File::put($archiveDir . '/user.json', json_encode($userData, JSON_PRETTY_PRINT));

            if ($user->customer) {
                File::put($archiveDir . '/customer.json', json_encode($user->customer->toArray(), JSON_PRETTY_PRINT));
            }

            $manifest = [
                'archive_id' => 'ARCHIVE-' . $userId . '-' . $archiveTimestamp,
                'user_id' => $userId,
                'archived_at' => Carbon::now()->toIso8601String(),
                'archive_reason' => $archiveReason ?? 'Manual archival',
                'archived_by' => auth()->id() ?? 'system',
                'archive_size' => $this->getArchiveSize($archiveDir),
            ];

            File::put($archiveDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            File::put($archiveDir . '/checksum.txt', $this->generateArchiveChecksum($archiveDir));

            return ['success' => true, 'archive_id' => $manifest['archive_id'], 'archive_path' => $archiveDir, 'manifest' => $manifest];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Archive failed: ' . $e->getMessage()];
        }
    }

    private function getArchiveSize($archiveDir)
    {
        $size = 0;
        foreach (File::allFiles($archiveDir) as $file) {
            $size += $file->getSize();
        }
        return round($size / 1024 / 1024, 2);
    }

    private function generateArchiveChecksum($archiveDir)
    {
        $files = File::allFiles($archiveDir);
        $checksums = [];

        foreach ($files as $file) {
            $checksums[$file->getRelativePathname()] = md5(File::get($file->getPathname()));
        }

        return json_encode([
            'overall_checksum' => md5(implode('', $checksums)),
            'file_checksums' => $checksums,
            'generated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);
    }

    public function verifyArchiveIntegrity($archivePath)
    {
        try {
            if (!File::exists($archivePath)) {
                return ['success' => false, 'message' => 'Archive not found'];
            }

            $checksumFile = $archivePath . '/checksum.txt';
            if (!File::exists($checksumFile)) {
                return ['success' => false, 'message' => 'Checksum file not found'];
            }

            $stored = json_decode(File::get($checksumFile), true);
            $current = json_decode($this->generateArchiveChecksum($archivePath), true);

            return [
                'success' => true,
                'is_intact' => $stored['overall_checksum'] === $current['overall_checksum'],
                'stored_checksum' => $stored['overall_checksum'],
                'current_checksum' => $current['overall_checksum'],
                'files_verified' => count($stored['file_checksums']),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Verification failed: ' . $e->getMessage()];
        }
    }

    public function listArchives()
    {
        if (!File::exists($this->archivePath)) {
            return [];
        }

        $archives = [];
        foreach (File::directories($this->archivePath) as $directory) {
            $manifestFile = $directory . '/manifest.json';
            if (File::exists($manifestFile)) {
                $manifest = json_decode(File::get($manifestFile), true);
                $archives[] = ['path' => $directory, 'manifest' => $manifest, 'size_mb' => $manifest['archive_size'] ?? 0];
            }
        }

        usort($archives, function ($a, $b) {
            return strtotime($b['manifest']['archived_at']) - strtotime($a['manifest']['archived_at']);
        });

        return $archives;
    }

    public function restoreArchive($archivePath)
    {
        if (!File::exists($archivePath)) {
            return ['success' => false, 'message' => 'Archive not found'];
        }

        $verification = $this->verifyArchiveIntegrity($archivePath);
        if (!$verification['success'] || !$verification['is_intact']) {
            return ['success' => false, 'message' => 'Archive integrity check failed'];
        }

        $manifest = json_decode(File::get($archivePath . '/manifest.json'), true);
        return ['success' => true, 'message' => 'Archive integrity verified. Ready for restore.', 'manifest' => $manifest];
    }

    public function deleteArchive($archivePath)
    {
        try {
            if (!File::exists($archivePath)) {
                return ['success' => false, 'message' => 'Archive not found'];
            }

            File::deleteDirectory($archivePath);
            return ['success' => true, 'message' => 'Archive deleted successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
        }
    }

    public function getArchivalStatistics()
    {
        $archives = $this->listArchives();
        $totalSize = array_sum(array_column($archives, 'size_mb'));

        return [
            'total_archives' => count($archives),
            'total_size_mb' => round($totalSize, 2),
            'oldest_archive' => !empty($archives) ? end($archives)['manifest']['archived_at'] : null,
            'newest_archive' => !empty($archives) ? $archives[0]['manifest']['archived_at'] : null,
            'archive_path' => $this->archivePath,
        ];
    }

    public function scheduleAutomaticArchival($inactiveDays = 365)
    {
        $inactiveUsers = User::where('last_login_at', '<', Carbon::now()->subDays($inactiveDays))->get();

        $results = [];
        foreach ($inactiveUsers as $user) {
            $results[] = ['user_id' => $user->id, 'result' => $this->archiveUserData($user->id, 'Automatic archival - inactive user')];
        }

        return [
            'total_inactive_users' => $inactiveUsers->count(),
            'archived_count' => count(array_filter($results, fn($r) => ($r['result']['success'] ?? false) === true)),
            'results' => $results,
        ];
    }
}