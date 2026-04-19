<?php

namespace App\Http\Controllers;

use App\Models\CustomerUpdateRequest;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CustomerUpdateRequestController extends Controller
{
    private const FIELD_MAP = [
        'phone_number' => 'phone_number',
        'email_address' => 'email_address',
        'address' => 'address',
        'occupation' => 'occupation',
    ];

    public function create()
    {
        $customer = optional(Auth::user())->customer;
        $requests = collect();

        if ($customer) {
            $requests = CustomerUpdateRequest::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->get();
        }

        return view('customer.update-request', [
            'customer' => $customer,
            'requests' => $requests,
            'allowedFields' => array_keys(self::FIELD_MAP),
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $customer = $user->customer;
        if (!$customer) {
            abort(404);
        }

        $payload = $request->validate([
            'field_name' => ['required', 'in:' . implode(',', array_keys(self::FIELD_MAP))],
            'requested_value' => ['required', 'string', 'max:2000'],
            'explanation' => ['nullable', 'string', 'max:2000'],
            'supporting_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $column = self::FIELD_MAP[$payload['field_name']];
        $documentName = null;
        $documentPath = null;

        if ($request->hasFile('supporting_document')) {
            $file = $request->file('supporting_document');
            $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
            $documentPath = $file->storeAs('customer-update-documents', $filename, 'public');
            $documentName = $file->getClientOriginalName();
        }

        $updateRequest = CustomerUpdateRequest::create([
            'customer_id' => $customer->id,
            'requested_by' => $user->id,
            'field_name' => $payload['field_name'],
            'current_value' => (string) ($customer->{$column} ?? ''),
            'requested_value' => $payload['requested_value'],
            'explanation' => $payload['explanation'] ?? null,
            'supporting_document_name' => $documentName,
            'supporting_document_path' => $documentPath,
        ]);

        $this->logAudit('customer_update.requested', $updateRequest, [
            'field_name' => $payload['field_name'],
            'customer_id' => $customer->id,
        ]);

        return redirect()->back()->with('status', 'Update request submitted for branch review.');
    }

    public function index()
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);

        $requests = CustomerUpdateRequest::query()
            ->with(['customer:id,full_name,branch_id', 'requester:id,name'])
            ->where('status', 'pending')
            ->when($user->role !== 'admin', function ($query) use ($branchId) {
                $query->whereHas('customer', function ($customerQuery) use ($branchId) {
                    $customerQuery->where('branch_id', $branchId);
                });
            })
            ->latest('id')
            ->get();

        return view('manager.customer-update-requests', [
            'requests' => $requests,
        ]);
    }

    public function approve(Request $request, CustomerUpdateRequest $updateRequest)
    {
        $payload = $request->validate([
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($updateRequest);

        $column = self::FIELD_MAP[$updateRequest->field_name] ?? null;
        if (!$column) {
            abort(422, 'Unsupported update field.');
        }

        $customer = $updateRequest->customer;
        $customer->{$column} = $updateRequest->requested_value;
        $customer->save();

        $updateRequest->update([
            'status' => 'approved',
            'reviewed_by' => Auth::id(),
            'review_note' => $payload['review_note'] ?? null,
            'reviewed_at' => now(),
        ]);

        $this->queueCustomerNotification($updateRequest, 'Your profile update request has been approved.');
        $this->logAudit('customer_update.approved', $updateRequest, [
            'customer_id' => $customer->id,
            'field_name' => $updateRequest->field_name,
        ]);

        return redirect()->back()->with('status', 'Customer update request approved.');
    }

    public function decline(Request $request, CustomerUpdateRequest $updateRequest)
    {
        $payload = $request->validate([
            'review_note' => ['required', 'string', 'max:2000'],
        ]);

        $this->ensureReviewAccess($updateRequest);

        $updateRequest->update([
            'status' => 'declined',
            'reviewed_by' => Auth::id(),
            'review_note' => $payload['review_note'],
            'reviewed_at' => now(),
        ]);

        $this->queueCustomerNotification($updateRequest, 'Your profile update request was declined. Please review the branch feedback.');
        $this->logAudit('customer_update.declined', $updateRequest, [
            'customer_id' => $updateRequest->customer_id,
            'field_name' => $updateRequest->field_name,
        ]);

        return redirect()->back()->with('status', 'Customer update request declined.');
    }

    public function download(CustomerUpdateRequest $updateRequest)
    {
        $this->ensureReviewAccess($updateRequest);
        if (!$updateRequest->supporting_document_path) {
            abort(404);
        }

        return Storage::disk('public')->download(
            $updateRequest->supporting_document_path,
            $updateRequest->supporting_document_name ?: 'supporting-document'
        );
    }

    private function ensureReviewAccess(CustomerUpdateRequest $updateRequest): void
    {
        $user = Auth::user();
        $branchId = $this->branchIdForUser($user);
        if ($user->role !== 'admin' && $branchId && (int) optional($updateRequest->customer)->branch_id !== $branchId) {
            abort(403);
        }
    }

    private function queueCustomerNotification(CustomerUpdateRequest $updateRequest, string $message): void
    {
        $customer = $updateRequest->customer;
        if (!$customer) {
            return;
        }

        $recipient = $customer->phone_number ?: $customer->email_address;
        if (!$recipient) {
            return;
        }

        SmsLog::create([
            'customer_id' => $customer->id,
            'channel' => $customer->phone_number ? 'sms' : 'email',
            'recipient' => $recipient,
            'message' => $message,
            'status' => 'queued',
        ]);
    }
}
