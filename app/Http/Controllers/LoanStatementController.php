<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class LoanStatementController extends Controller
{
    public function showStaff(Loan $loan)
    {
        $this->ensureStaffAccess($loan);
        return $this->renderStatement($loan);
    }

    public function showCustomer(Loan $loan)
    {
        $user = Auth::user();
        if (!$user->customer || $loan->customer_id !== $user->customer->id) {
            abort(403);
        }

        return $this->renderStatement($loan);
    }

    private function renderStatement(Loan $loan)
    {
        $startDate = request('start_date');
        $endDate = request('end_date');
        $format = request('format', 'html'); // html or pdf

        $loan->load([
            'customer',
            'customer.branch',
            'creator',
            'repayments' => function ($query) use ($startDate, $endDate) {
                if ($startDate) {
                    $query->whereDate('payment_date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('payment_date', '<=', $endDate);
                }
                $query->orderBy('payment_date');
            },
            'schedules' => function ($query) use ($startDate, $endDate) {
                if ($startDate) {
                    $query->whereDate('due_date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('due_date', '<=', $endDate);
                }
                $query->orderBy('installment_number');
            },
        ]);

        $totalPaid = (float) $loan->repayments->sum('installment_amount');
        $totalDue = (float) $loan->schedules->sum('amount_due');
        $balance = max(0, $totalDue - $totalPaid);
        
        // Calculate additional metrics
        $paidInstallments = $loan->schedules->where('status', 'Paid')->count();
        $pendingInstallments = $loan->schedules->whereIn('status', ['Pending', 'Partial'])->count();
        $overdueInstallments = $loan->schedules->where('status', 'Overdue')->count();
        
        // Generate statement reference number
        $statementRef = 'STMT-' . date('Ymd') . '-' . $loan->id . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        $statementData = [
            'loan' => $loan,
            'totalPaid' => $totalPaid,
            'totalDue' => $totalDue,
            'balance' => $balance,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'statementRef' => $statementRef,
            'generatedAt' => now(),
            'paidInstallments' => $paidInstallments,
            'pendingInstallments' => $pendingInstallments,
            'overdueInstallments' => $overdueInstallments,
            'format' => $format,
        ];

        if ($format === 'pdf') {
            return $this->generatePdfStatement($statementData);
        }

        return view('loans.statement', $statementData);
    }

    /**
     * Generate PDF statement
     */
    private function generatePdfStatement($data)
    {
        // This would typically use a PDF library like DomPDF or Snappy
        // For now, we'll return a view that can be printed to PDF
        $pdfView = view('loans.statement-pdf', $data)->render();
        
        // Store for later download if needed
        $statementPath = storage_path('app/statements/' . $data['statementRef'] . '.html');
        file_put_contents($statementPath, $pdfView);
        
        return response($pdfView)
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'attachment; filename="' . $data['statementRef'] . '.html"');
    }

    private function ensureStaffAccess(Loan $loan): void
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

        if ($user->role === 'loan_employee' && (int) $loan->created_by !== (int) $user->id) {
            abort(403);
        }

        if ($user->role === 'loan_manager') {
            $employeeIds = User::where('manager_id', $user->id)->pluck('id')->all();
            if (!in_array((int) $loan->created_by, array_merge([(int) $user->id], array_map('intval', $employeeIds)), true)) {
                abort(403);
            }
        }
    }
}
