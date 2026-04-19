<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    private $backupService;

    public function __construct(BackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    public function index()
    {
        $backups = $this->backupService->listBackups();
        $statistics = $this->backupService->getBackupStatistics();

        return view('admin.backups', compact('backups', 'statistics'));
    }

    public function createFullBackup()
    {
        $result = $this->backupService->createFullBackup();

        $this->logAudit('backup.full_created', null, [
            'success' => $result['success'],
            'backup_path' => $result['backup_path'] ?? null,
        ]);

        return response()->json($result);
    }

    public function createDatabaseBackup()
    {
        $result = $this->backupService->createDatabaseBackup();

        $this->logAudit('backup.database_created', null, [
            'success' => $result['success'],
            'backup_path' => $result['backup_path'] ?? null,
        ]);

        return response()->json($result);
    }

    public function restore(Request $request)
    {
        $request->validate([
            'backup_path' => 'required|string',
            'confirm' => 'required|accepted',
        ]);

        $result = $this->backupService->restoreBackup($request->backup_path);

        $this->logAudit('backup.restore', null, [
            'success' => $result['success'],
            'backup_path' => $request->backup_path,
        ]);

        return response()->json($result);
    }

    public function delete(Request $request)
    {
        $request->validate([
            'backup_path' => 'required|string',
        ]);

        try {
            if (file_exists($request->backup_path)) {
                \Illuminate\Support\Facades\File::deleteDirectory($request->backup_path);

                $this->logAudit('backup.deleted', null, [
                    'backup_path' => $request->backup_path,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Backup deleted successfully'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Backup not found'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Delete failed: ' . $e->getMessage()
            ]);
        }
    }

    public function statistics()
    {
        $statistics = $this->backupService->getBackupStatistics();
        return response()->json($statistics);
    }

    public function download(Request $request)
    {
        $request->validate([
            'backup_path' => 'required|string',
        ]);

        try {
            $backupPath = $request->backup_path;

            if (!file_exists($backupPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup not found'
                ]);
            }

            $zipFile = $backupPath . '.zip';
            $zip = new \ZipArchive();

            if ($zip->open($zipFile, \ZipArchive::CREATE) === TRUE) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($backupPath),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = substr($filePath, strlen($backupPath) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }

                $zip->close();
                return response()->download($zipFile)->deleteFileAfterSend(true);
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not create zip file'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Download failed: ' . $e->getMessage()
            ]);
        }
    }

    public function testIntegrity(Request $request)
    {
        $request->validate([
            'backup_path' => 'required|string',
        ]);

        try {
            $backupPath = $request->backup_path;

            if (!file_exists($backupPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup not found'
                ]);
            }

            $metadataFile = $backupPath . '/metadata.json';
            if (!file_exists($metadataFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup metadata missing - backup may be corrupted'
                ]);
            }

            $metadata = json_decode(file_get_contents($metadataFile), true);

            if (!file_exists($metadata['database_backup'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Database backup file missing'
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Backup integrity verified',
                'metadata' => $metadata
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Integrity check failed: ' . $e->getMessage()
            ]);
        }
    }
}