<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LoanDocumentController extends Controller
{
    public function store(Request $request, Loan $loan)
    {
        $this->ensureLoanAccess($loan);

        $payload = $request->validate([
            'document' => ['required', 'file', 'max:5120'],
        ]);

        $file = $payload['document'];
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('loan-documents', $filename, 'public');

        LoanDocument::create([
            'loan_id' => $loan->id,
            'uploaded_by' => Auth::id(),
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        $this->logAudit('loan.document_uploaded', $loan, [
            'original_name' => $file->getClientOriginalName(),
        ]);

        return redirect()->back()->with('status', 'Document uploaded.');
    }

    public function download(LoanDocument $document)
    {
        $this->ensureLoanAccess($document->loan);
        return Storage::disk('public')->download($document->stored_path, $document->original_name);
    }

    private function ensureLoanAccess(Loan $loan): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(401);
        }

        if ($user->role === 'admin') {
            return;
        }

        $branchId = $this->branchIdForUser($user);
        if ($branchId && (int) optional($loan->customer)->branch_id !== $branchId) {
            abort(403);
        }
    }
}
