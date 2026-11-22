<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\ChangeApproval;
use App\Models\ChecklistItem;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UpdatePlanTransition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePlanController extends Controller
{
    private const APPROVAL_CRITICAL_FIELDS = [
        'impact', 'risk_reason', 'current_version', 'target_version', 'operator_id',
        'test_plan', 'implementation_plan', 'verification_plan', 'rollback_plan', 'rollback_trigger',
        'backup_reference', 'backup_verified', 'restore_test_passed',
    ];

    private const POST_IMPLEMENTATION_STATES = [
        'EXECUTING', 'VERIFYING', 'FAILED', 'ROLLBACK_REQUIRED', 'ROLLING_BACK', 'ROLLBACK_FAILED',
    ];

    public function index(Request $request)
    {
        $plans = UpdatePlan::with('assets')->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderByRaw('planned_start_at IS NULL, planned_start_at')->orderBy('plan_code')->get();
        $statusGroups = $plans->groupBy('status');
        $timeGroups = $plans->groupBy(fn (UpdatePlan $plan) => $plan->planned_start_at?->toDateString() ?? '未設定');

        return view('update-plans.index', compact('plans', 'statusGroups', 'timeGroups'));
    }

    public function create()
    {
        $this->authorize('create', UpdatePlan::class);

        return view('update-plans.create', ['assets' => Asset::with('components')->get()]);
    }

    public function store(Request $r, AuditLogger $audit)
    {
        $this->authorize('create', UpdatePlan::class);
        $data = $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string', 'asset_ids' => 'required|array|min:1', 'asset_ids.*' => Rule::exists('assets', 'id')->withoutTrashed(), 'component_ids' => 'array', 'component_ids.*' => 'exists:asset_components,id', 'impact' => 'required|string|max:255', 'risk_level' => 'nullable|string', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'change_type' => 'nullable|in:Standard,Normal,Emergency', 'reason_type' => 'nullable|in:EOL,Security,CVE,Maintenance,Incident']);
        $plan = DB::transaction(function () use ($data, $r, $audit) {
            if (($data['change_type'] ?? 'Normal') === 'Normal' && Asset::whereKey($data['asset_ids'])->where('operational_status', 'Retired')->exists()) {
                throw ValidationException::withMessages(['asset_ids' => '廃止済みの資産は通常の変更計画に追加できません。']);
            }
            $plan = UpdatePlan::create(['plan_code' => 'CHG-'.strtoupper(bin2hex(random_bytes(4))), 'title' => $data['title'], 'description' => $data['description'] ?? null, 'impact' => $data['impact'], 'risk_level' => $data['risk_level'] ?? null, 'current_version' => $data['current_version'] ?? null, 'target_version' => $data['target_version'] ?? null, 'change_type' => $data['change_type'] ?? null, 'reason_type' => $data['reason_type'] ?? null, 'created_by' => $r->user()->id]);
            $plan->assets()->sync($data['asset_ids']);
            foreach ($data['component_ids'] ?? [] as $id) {
                $c = AssetComponent::findOrFail($id);
                if (! in_array($c->asset_id, $data['asset_ids'])) {
                    throw ValidationException::withMessages(['component_ids' => 'Component does not belong to selected asset.']);
                } DB::table('update_plan_components')->insert(['update_plan_id' => $plan->id, 'component_id' => $id, 'asset_id' => $c->asset_id, 'created_at' => now(), 'updated_at' => now()]);
            } $audit->log($plan, 'created', [], ['plan_code' => $plan->plan_code]);

            return $plan;
        });

        return redirect()->route('update-plans.show', $plan);
    }

    public function show(UpdatePlan $updatePlan)
    {
        $updatePlan->load(['assets', 'components', 'checklistItems.passedCorrections', 'approvals.approver', 'approvals.invalidation', 'incidents', 'operator', 'reviewer', 'sourceAssessment.vulnerability']);
        $operators = Gate::allows('update', $updatePlan)
            ? User::whereHas('roles', fn ($roles) => $roles->where('name', 'Operator'))->orderBy('name')->get()
            : collect();

        return view('update-plans.show', ['plan' => $updatePlan, 'operators' => $operators]);
    }

    public function update(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        $this->authorize('update', $updatePlan);
        $data = $r->validate(['impact' => 'nullable|string|max:255', 'test_plan' => 'nullable|string', 'implementation_plan' => 'nullable|string', 'verification_plan' => 'nullable|string', 'rollback_plan' => 'nullable|string', 'rollback_trigger' => 'nullable|string', 'backup_reference' => 'nullable|string', 'backup_verified' => 'nullable|boolean', 'restore_test_passed' => 'nullable|boolean', 'planned_start_at' => 'nullable|date', 'planned_end_at' => 'nullable|date', 'operator_id' => 'nullable|exists:users,id', 'current_version' => 'nullable|string', 'target_version' => 'nullable|string', 'risk_reason' => 'nullable|string']);
        DB::transaction(function () use ($updatePlan, $data, $audit): void {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($updatePlan->id);
            $this->authorize('update', $plan);
            $oldValues = $plan->only(array_keys($data));
            $plan->fill($data);

            $changedCriticalValues = array_intersect_key($oldValues, $plan->getDirty(), array_flip(self::APPROVAL_CRITICAL_FIELDS));
            if ($changedCriticalValues !== [] && $this->implementationStarted($plan)) {
                throw ValidationException::withMessages(collect(array_keys($changedCriticalValues))
                    ->mapWithKeys(fn (string $field) => [$field => '実施開始後は重要な計画項目を変更できません。'])
                    ->all());
            }
            $approvals = $changedCriticalValues === [] ? collect() : $plan->validApprovals()->get();
            if ($approvals->isNotEmpty()) {
                $oldState = $plan->only(['status', 'approver_id', 'blocked_from_status']);
                $plan->fill(['status' => 'PENDING_APPROVAL', 'approver_id' => null, 'blocked_from_status' => null]);
                $newState = $plan->only(array_keys($oldState));
                $newCriticalValues = $plan->only(array_keys($changedCriticalValues));
                foreach ($approvals as $approval) {
                    $audit->log($approval, ChangeApproval::INVALIDATED_EVENT, $changedCriticalValues + $oldState, $newCriticalValues + $newState + [
                        'update_plan_id' => $plan->id,
                        'reason' => 'critical_plan_fields_changed',
                    ]);
                }
                $oldValues += $oldState;
                $data += $newState;
            }

            $plan->save();
            $audit->log($plan, 'plan_updated', $oldValues, $data);
        });

        return back();
    }

    private function implementationStarted(UpdatePlan $plan): bool
    {
        if ($plan->actual_start_at !== null) {
            return true;
        }
        if (in_array($plan->status, self::POST_IMPLEMENTATION_STATES, true)) {
            return true;
        }

        return $plan->status === 'BLOCKED'
            && in_array($plan->blocked_from_status, self::POST_IMPLEMENTATION_STATES, true);
    }

    public function transition(Request $r, UpdatePlan $updatePlan, UpdatePlanTransition $service)
    {
        $this->authorize('transition', $updatePlan);
        $d = $r->validate([
            'status' => 'required|string',
            'completion_result' => 'nullable|string',
            'action_summary' => 'nullable|string',
            'details' => 'nullable|string',
            'rollback_reason' => 'required_if:status,ROLLED_BACK|nullable|string',
            'restore_verification_result' => 'required_if:status,ROLLED_BACK|nullable|string',
            'incident' => 'nullable|array:title,description',
            'incident.title' => 'required_if:status,ROLLBACK_FAILED|nullable|string|max:255',
            'incident.description' => 'nullable|string',
        ]);
        $service->transition($updatePlan, $d['status'], $d);

        return back();
    }

    public function checklist(Request $r, UpdatePlan $updatePlan, AuditLogger $audit)
    {
        $this->authorize('update', $updatePlan);
        $d = $r->validate(['phase' => ['required', Rule::in(ChecklistItem::PHASES)], 'title' => 'required|string|max:255', 'description' => 'nullable|string', 'expected_result' => 'nullable|string', 'is_required' => 'boolean']);
        DB::transaction(function () use ($updatePlan, $d, $audit): void {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($updatePlan->id);
            $this->authorize('update', $plan);
            $item = $plan->checklistItems()->create($d + ['status' => 'Pending']);
            $audit->log($item, 'created');
        });

        return back();
    }

    public function checklistStatus(Request $r, ChecklistItem $item, AuditLogger $audit)
    {
        $ability = $r->input('status') === 'Waived' ? 'approve' : 'update';
        $this->authorize($ability, $item->plan);

        return DB::transaction(function () use ($r, $item, $audit, $ability) {
            $plan = UpdatePlan::query()->lockForUpdate()->findOrFail($item->update_plan_id);
            $this->authorize($ability, $plan);
            $item->refresh();
            abort_if($item->correction_of_id, 403);
            $d = $r->validate([
                'status' => 'required|in:Passed,Failed,Blocked,Not Applicable,Waived,Pending',
                'evidence' => 'nullable|string',
                'exception_reason' => 'required_if:status,Waived|nullable|string',
                'waiver_scope' => ['required_if:status,Waived', 'nullable', Rule::in(array_keys(ChecklistItem::WAIVER_SCOPES))],
            ]);

            if ($item->status === 'Failed') {
                if ($d['status'] !== 'Passed') {
                    throw ValidationException::withMessages(['status' => '不合格の記録は変更できません。是正記録を追加してください。']);
                }
                $correctionData = $r->validate(['correction_reason' => 'required|string', 'evidence' => 'required|string']);
                $correction = $item->passedCorrections()->firstOrCreate(['status' => 'Passed'], [
                    'update_plan_id' => $plan->id, 'phase' => $item->phase, 'sequence' => $item->sequence + 1,
                    'title' => mb_substr($item->title, 0, 220).'（項目 #'.$item->id.' の是正）',
                    'description' => $correctionData['correction_reason'], 'is_required' => $item->is_required,
                    'evidence' => $correctionData['evidence'], 'completed_by' => $r->user()->id, 'completed_at' => now(),
                ]);
                if ($correction->wasRecentlyCreated) {
                    $audit->log($correction, 'checklist_correction_recorded', ['failed_item_id' => $item->id], ['correction_reason' => $correctionData['correction_reason']]);
                }

                return back()->with('status', '是正記録を確認できます。元の不合格記録は保持されています。');
            }

            $fields = ['status', 'evidence', 'exception_reason', 'waiver_scope', 'waived_by', 'waived_at'];
            $old = $item->only($fields);
            if ($d['status'] === 'Waived') {
                abort_if($item->status === 'Passed', 403);
                $item->update([
                    'status' => 'Waived', 'exception_reason' => $d['exception_reason'], 'waiver_scope' => $d['waiver_scope'],
                    'waived_by' => $r->user()->id, 'waived_at' => now(),
                ]);
            } else {
                $item->update([
                    'status' => $d['status'], 'evidence' => $d['evidence'] ?? null, 'completed_by' => $r->user()->id, 'completed_at' => now(),
                    'exception_reason' => null, 'waiver_scope' => null, 'waived_by' => null, 'waived_at' => null,
                ]);
            }
            $audit->log($item, 'checklist_status', $old, $item->only($fields));

            return back();
        });
    }

    public function approve(Request $r, UpdatePlan $updatePlan, UpdatePlanTransition $service)
    {
        $this->authorize('approve', $updatePlan);
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'comments' => 'nullable|string']);
        $service->approve($updatePlan, $r->user(), $d['decision'], $d['comments'] ?? null);

        return back();
    }
}
