<?php

namespace App\AiOffice\Http\Controllers;

use App\AiOffice\Http\Resources\ApprovalResource;
use App\AiOffice\Models\Approval;
use App\AiOffice\Services\ApprovalNotPendingException;
use App\AiOffice\Services\ApprovalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Approval::class);
        $this->approvals->expireOverdue();

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...Approval::STATUSES, 'all'])],
            'risk_level' => ['nullable', Rule::in(Approval::RISK_LEVELS)],
            'project_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $status = $filters['status'] ?? 'pending';

        $approvals = Approval::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($filters['risk_level'] ?? null, fn ($query, $risk) => $query->where('risk_level', $risk))
            ->when($filters['project_id'] ?? null, fn ($query, $id) => $query->where('project_id', $id))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => ApprovalResource::collection($approvals)->resolve(),
            'meta' => [
                'current_page' => $approvals->currentPage(),
                'last_page' => $approvals->lastPage(),
                'per_page' => $approvals->perPage(),
                'total' => $approvals->total(),
            ],
        ]);
    }

    public function show(Approval $approval): JsonResponse
    {
        $this->authorize('view', $approval);
        $this->approvals->expireOverdue();
        $approval->refresh();

        return response()->json([
            'success' => true,
            'data' => (new ApprovalResource($approval))->resolve(),
        ]);
    }

    public function approve(Request $request, Approval $approval): JsonResponse
    {
        $this->authorize('review', $approval);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $approval = $this->approvals->approve($approval, $request->user(), $validated['comment'] ?? null);
        } catch (ApprovalNotPendingException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => (new ApprovalResource($approval))->resolve(),
        ]);
    }

    public function reject(Request $request, Approval $approval): JsonResponse
    {
        $this->authorize('review', $approval);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $approval = $this->approvals->reject($approval, $request->user(), $validated['comment'] ?? null);
        } catch (ApprovalNotPendingException $e) {
            return $this->conflict($e->getMessage());
        }

        return response()->json([
            'success' => true,
            'data' => (new ApprovalResource($approval))->resolve(),
        ]);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $message,
            ],
        ], 422);
    }
}
