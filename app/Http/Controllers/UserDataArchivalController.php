<?php

namespace App\Http\Controllers;

use App\Services\UserDataArchivalService;
use App\Models\User;
use Illuminate\Http\Request;

class UserDataArchivalController extends Controller
{
    private $archivalService;

    public function __construct(UserDataArchivalService $archivalService)
    {
        $this->archivalService = $archivalService;
    }

    public function index()
    {
        $archives = $this->archivalService->listArchives();
        $statistics = $this->archivalService->getArchivalStatistics();

        return view('admin.user-archival', compact('archives', 'statistics'));
    }

    public function archiveUser(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'archive_reason' => ['nullable', 'string', 'max:500'],
            'confirm' => ['accepted'],
        ]);

        $result = $this->archivalService->archiveUserData(
            $request->user_id,
            $request->archive_reason ?? 'Manual archival'
        );

        if ($result['success']) {
            $this->logAudit('user_data.archived', User::find($request->user_id), [
                'archive_id' => $result['archive_id'],
                'archive_reason' => $request->archive_reason,
            ]);
        }

        return response()->json($result);
    }

    public function listArchives()
    {
        return response()->json($this->archivalService->listArchives());
    }

    public function verifyArchive(Request $request)
    {
        $request->validate([
            'archive_path' => ['required', 'string'],
        ]);

        return response()->json($this->archivalService->verifyArchiveIntegrity($request->archive_path));
    }

    public function restoreArchive(Request $request)
    {
        $request->validate([
            'archive_path' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        $result = $this->archivalService->restoreArchive($request->archive_path);

        if ($result['success']) {
            $this->logAudit('user_data.restore_attempt', null, [
                'archive_path' => $request->archive_path,
            ]);
        }

        return response()->json($result);
    }

    public function deleteArchive(Request $request)
    {
        $request->validate([
            'archive_path' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        $result = $this->archivalService->deleteArchive($request->archive_path);

        if ($result['success']) {
            $this->logAudit('user_data.archive_deleted', null, [
                'archive_path' => $request->archive_path,
            ]);
        }

        return response()->json($result);
    }

    public function getStatistics()
    {
        return response()->json($this->archivalService->getArchivalStatistics());
    }

    public function scheduleAutomaticArchival(Request $request)
    {
        $request->validate([
            'inactive_days' => ['nullable', 'integer', 'min:30'],
        ]);

        $result = $this->archivalService->scheduleAutomaticArchival(
            $request->inactive_days ?? 365
        );

        $this->logAudit('user_data.auto_archival_scheduled', null, [
            'inactive_days' => $request->inactive_days ?? 365,
            'archived_count' => $result['archived_count'],
        ]);

        return response()->json($result);
    }

    public function getArchiveManifest(Request $request)
    {
        $request->validate([
            'archive_path' => ['required', 'string'],
        ]);

        if (!\Illuminate\Support\Facades\File::exists($request->archive_path)) {
            return response()->json(['success' => false, 'message' => 'Archive not found'], 404);
        }

        $manifestFile = $request->archive_path . '/manifest.json';
        if (!\Illuminate\Support\Facades\File::exists($manifestFile)) {
            return response()->json(['success' => false, 'message' => 'Manifest not found'], 404);
        }

        $manifest = json_decode(\Illuminate\Support\Facades\File::get($manifestFile), true);
        return response()->json($manifest);
    }

    public function downloadArchive(Request $request)
    {
        $request->validate([
            'archive_path' => ['required', 'string'],
        ]);

        if (!\Illuminate\Support\Facades\File::exists($request->archive_path)) {
            return response()->json(['success' => false, 'message' => 'Archive not found'], 404);
        }

        $zipFileName = basename($request->archive_path) . '.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);

        if (!\Illuminate\Support\Facades\File::exists(storage_path('app/temp'))) {
            \Illuminate\Support\Facades\File::makeDirectory(storage_path('app/temp'), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
            $files = \Illuminate\Support\Facades\File::allFiles($request->archive_path);
            foreach ($files as $file) {
                $zip->addFile($file->getPathname(), $file->getRelativePathname());
            }
            $zip->close();
        }

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }
}