<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AuditEvent;
use App\Models\ChangeApproval;
use App\Models\ChecklistItem;
use App\Models\Incident;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\WorkRecord;
use App\Services\UpdatePlanTransition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdatePlanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Operator']));

        return $user;
    }

    private function plan(User $user): UpdatePlan
    {
        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $asset = Asset::create(['asset_code' => 'ASSET-'.$suffix, 'name' => 'Demo', 'asset_type' => 'Server']);
        $plan = UpdatePlan::create(['plan_code' => 'CHG-'.$suffix, 'title' => 'Test patch', 'impact' => 'User impact assessed', 'created_by' => $user->id]);
        $plan->assets()->attach($asset->id);

        return $plan;
    }

    private function recordApproval(UpdatePlan $plan): ChangeApproval
    {
        $reviewer = User::factory()->create();
        $reviewer->roles()->attach(Role::firstOrCreate(['name' => 'Reviewer']));
        $this->actingAs($reviewer)->post(route('update-plans.approve', $plan), [
            'decision' => 'APPROVED', 'comments' => 'Reviewed the change plan.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $plan->approvals()->latest('id')->firstOrFail();
    }

    /** @return array{UpdatePlan, ChangeApproval} */
    private function approvedPlan(User $operator): array
    {
        $plan = $this->plan($operator);
        $plan->update([
            'status' => 'PENDING_APPROVAL', 'operator_id' => $operator->id, 'target_version' => '2.0',
            'backup_reference' => 'backup-1', 'backup_verified' => true, 'restore_test_passed' => true,
            'planned_start_at' => '2026-11-20 01:00:00', 'planned_end_at' => '2026-11-20 03:00:00',
        ]);
        $approval = $this->recordApproval($plan);
        $this->actingAs($operator)->post(route('update-plans.transition', $plan), ['status' => 'APPROVED'])
            ->assertRedirect()->assertSessionHasNoErrors();

        return [$plan->refresh(), $approval];
    }

    private function assertApprovalInvalidated(ChangeApproval $approval, User $actor, array $before, array $after, string $from): void
    {
        $this->assertDatabaseHas('update_plans', $after + [
            'id' => $approval->update_plan_id, 'status' => 'PENDING_APPROVAL',
            'approver_id' => null, 'blocked_from_status' => null,
        ]);
        $this->assertSame($approval->getRawOriginal(), $approval->fresh()->getRawOriginal());

        $event = $approval->invalidation()->sole();
        $this->assertSame($actor->id, $event->user_id);
        $this->assertSame($from, $event->old_values['status']);
        $this->assertSame('PENDING_APPROVAL', $event->new_values['status']);
        $this->assertSame($approval->update_plan_id, $event->new_values['update_plan_id']);
        $this->assertSame('critical_plan_fields_changed', $event->new_values['reason']);
        $this->assertSame($before, array_intersect_key($event->old_values, $before));
        $this->assertSame($after, array_intersect_key($event->new_values, $after));
    }

    public function test_failed_checklist_item_cannot_be_directly_passed(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $item = ChecklistItem::create(['update_plan_id' => $plan->id, 'phase' => '2 Pre-change Validation', 'title' => 'test', 'status' => 'Failed']);
        $this->actingAs($user)->postJson(route('checklist-items.status', $item), ['status' => 'Passed', 'evidence' => 'patched'])->assertUnprocessable();
        $this->assertDatabaseHas('update_checklist_items', ['id' => $item->id, 'status' => 'Failed']);
    }

    public function test_blocked_plan_records_and_only_resumes_to_prior_state(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $this->actingAs($user);
        app(UpdatePlanTransition::class)->transition($plan, 'ASSESSMENT');
        $blocked = app(UpdatePlanTransition::class)->transition($plan, 'BLOCKED');
        $this->assertSame('ASSESSMENT', $blocked->blocked_from_status);
        try {
            app(UpdatePlanTransition::class)->transition($blocked, 'TESTING');
            $this->fail('Blocked status must not jump to a different state.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $resumed = app(UpdatePlanTransition::class)->transition($blocked, 'ASSESSMENT');
        $this->assertSame('ASSESSMENT', $resumed->status);
        $this->assertNull($resumed->blocked_from_status);
    }

    public function test_component_from_another_asset_is_rejected_without_creating_a_plan(): void
    {
        $user = $this->operator();
        $asset = Asset::create(['asset_code' => 'ASSET-A', 'name' => 'A', 'asset_type' => 'Server']);
        $other = Asset::create(['asset_code' => 'ASSET-B', 'name' => 'B', 'asset_type' => 'Server']);
        $component = AssetComponent::create(['asset_id' => $other->id, 'component_type' => 'Runtime', 'product_name' => 'PHP', 'version' => '8.1']);

        $this->actingAs($user)->postJson(route('update-plans.store'), [
            'title' => 'Cross asset test', 'impact' => 'User impact assessed', 'asset_ids' => [$asset->id], 'component_ids' => [$component->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('update_plans', 0);
        $this->assertDatabaseCount('update_plan_assets', 0);
        $this->assertDatabaseCount('update_plan_components', 0);
    }

    public function test_rollback_failure_and_incident_creation_share_a_transaction(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['status' => 'ROLLING_BACK'])->save();
        $this->actingAs($user);

        app(UpdatePlanTransition::class)->transition($plan, 'ROLLBACK_FAILED', [
            'incident' => ['title' => 'Rollback failed', 'description' => 'Failure'],
        ]);

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'ROLLBACK_FAILED']);
        $this->assertDatabaseHas('incidents', ['title' => 'Rollback failed', 'status' => 'NEW']);
        $this->assertDatabaseHas('incident_update_plans', ['update_plan_id' => $plan->id]);
    }

    public function test_incident_insert_failure_rolls_back_plan_transition_and_records(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['status' => 'ROLLING_BACK'])->save();
        $this->actingAs($user);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with(strtolower(ltrim($query)), 'insert into `incidents`')) {
                throw new \RuntimeException('Injected incident creation failure.');
            }
        });

        try {
            app(UpdatePlanTransition::class)->transition($plan, 'ROLLBACK_FAILED', [
                'incident' => ['title' => 'Rollback failed', 'description' => 'Failure'],
            ]);
            $this->fail('Incident insert failure should abort the whole transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected incident creation failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'ROLLING_BACK']);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseMissing('audit_events', ['auditable_type' => UpdatePlan::class, 'auditable_id' => $plan->id, 'event_type' => 'status_transition']);
    }

    public function test_guard_matrix_rejects_invalid_transitions_without_changing_status(): void
    {
        $cases = [
            'no impact blocks testing' => ['ASSESSMENT', 'TESTING', ['impact' => null], []],
            'no test evidence blocks approval submission' => ['TESTING', 'PENDING_APPROVAL', [], []],
            'high risk without independent approval blocks approval' => ['PENDING_APPROVAL', 'APPROVED', ['risk_level' => 'HIGH'], []],
            'no backup evidence blocks execution' => ['SCHEDULED', 'EXECUTING', ['operator_id' => 1, 'planned_start_at' => now(), 'planned_end_at' => now()->addHour()], []],
            'CHG restore verification blocks execution' => ['SCHEDULED', 'EXECUTING', ['change_type' => 'Normal', 'backup_verified' => true, 'backup_reference' => 'backup-1', 'restore_test_passed' => false, 'operator_id' => 1, 'planned_start_at' => now(), 'planned_end_at' => now()->addHour()], []],
            'missing operator and maintenance window block execution' => ['SCHEDULED', 'EXECUTING', ['backup_verified' => true, 'backup_reference' => 'backup-1', 'restore_test_passed' => true], []],
            'no post change evidence blocks completion' => ['VERIFYING', 'COMPLETED', [], []],
            'unverified rollback blocks rolled back state' => ['ROLLING_BACK', 'ROLLED_BACK', [], ['rollback_reason' => 'Failed deployment', 'restore_verification_result' => 'Passed']],
            'rollback failure requires incident and keeps plan rolling back' => ['ROLLING_BACK', 'ROLLBACK_FAILED', [], []],
            'execution failure cannot directly complete' => ['FAILED', 'COMPLETED', [], []],
            'blocked plan cannot resume to another state' => ['BLOCKED', 'TESTING', ['blocked_from_status' => 'ASSESSMENT'], []],
            'rolled back is terminal and distinct from completed' => ['ROLLED_BACK', 'COMPLETED', [], []],
            'rollback requires reason and restore result' => ['ROLLING_BACK', 'ROLLED_BACK', [], []],
        ];

        foreach ($cases as $name => [$from, $to, $attributes, $data]) {
            $this->assertGuardRejects($name, $from, $to, $attributes, $data);
        }
    }

    private function assertGuardRejects(string $name, string $from, string $to, array $attributes, array $data): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        if (($attributes['operator_id'] ?? null) === 1) {
            $attributes['operator_id'] = $user->id;
        }
        $plan->forceFill($attributes + ['status' => $from])->save();
        if (in_array($from, ['SCHEDULED', 'VERIFYING'], true)) {
            $this->recordApproval($plan);
        }
        $this->actingAs($user);
        try {
            app(UpdatePlanTransition::class)->transition($plan, $to, $data);
            $this->fail("Guard should reject: {$name}");
        } catch (ValidationException) {
            $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => $from]);
        }
    }

    public function test_rollback_failure_creates_linked_incident_and_audit(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->assets()->first()->update(['environment' => 'Production']);
        $plan->forceFill(['status' => 'ROLLING_BACK'])->save();
        $this->actingAs($user);
        $result = app(UpdatePlanTransition::class)->transition($plan, 'ROLLBACK_FAILED', ['incident' => ['title' => 'Rollback failed', 'description' => 'Recovery issue']]);
        $incident = Incident::firstOrFail();
        $this->assertSame('ROLLBACK_FAILED', $result->status);
        $this->assertDatabaseHas('incident_update_plans', ['incident_id' => $incident->id, 'update_plan_id' => $plan->id]);
        $this->assertDatabaseHas('audit_events', ['auditable_id' => $plan->id, 'event_type' => 'status_transition']);
        $this->assertDatabaseHas('work_records', ['update_plan_id' => $plan->id, 'result' => 'Failed']);
    }

    public function test_full_rollback_path_records_incident_work_and_audit_consistently(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['status' => 'APPROVED', 'operator_id' => $user->id, 'backup_verified' => true, 'backup_reference' => 'backup-42', 'restore_test_passed' => true, 'current_version' => '1.0', 'target_version' => '2.0', 'planned_start_at' => now()->subHour(), 'planned_end_at' => now()->addHour()])->save();
        $this->recordApproval($plan);
        $this->actingAs($user);
        $service = app(UpdatePlanTransition::class);
        $service->transition($plan, 'SCHEDULED');
        $started = $service->transition($plan, 'EXECUTING');
        $service->transition($started, 'FAILED', ['action_summary' => 'Simulated failure']);
        $service->transition($started->fresh(), 'ROLLBACK_REQUIRED', ['action_summary' => 'Rollback required', 'incident' => ['title' => 'Deployment failure', 'description' => 'Simulated execution failure']]);
        $service->transition($started->fresh(), 'ROLLING_BACK', ['action_summary' => 'Restoring']);
        $plan->checklistItems()->create(['phase' => '5 Post-change Verification', 'title' => 'Restore validation', 'status' => 'Passed', 'evidence' => 'Service health verified']);
        $final = $service->transition($started->fresh(), 'ROLLED_BACK', ['rollback_reason' => 'Upgrade failed', 'restore_verification_result' => 'Prior version restored; service checks passed']);
        $this->assertSame('ROLLED_BACK', $final->status);
        $this->assertNotNull($final->actual_start_at);
        $this->assertNotNull($final->actual_end_at);
        $this->assertSame('backup-42', $final->backup_reference);
        $this->assertSame('1.0', $final->current_version);
        $this->assertSame('2.0', $final->target_version);
        $this->assertGreaterThanOrEqual(4, $plan->workRecords()->count());
        $incidentId = DB::table('incident_update_plans')->where('update_plan_id', $plan->id)->value('incident_id');
        $this->assertNotNull($incidentId);
        $this->assertDatabaseHas('work_records', ['update_plan_id' => $plan->id, 'incident_id' => $incidentId]);
        $this->assertStringContainsString('Upgrade failed', (string) $plan->workRecords()->where('summary', 'like', '%ROLLED_BACK%')->value('details'));
        $this->assertStringContainsString('Prior version restored', (string) $plan->workRecords()->where('summary', 'like', '%ROLLED_BACK%')->value('details'));
        $this->assertDatabaseHas('audit_events', ['auditable_id' => $plan->id, 'event_type' => 'status_transition']);
        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'ROLLED_BACK']);
    }

    public function test_actual_execution_times_do_not_overwrite_planned_window(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $start = Carbon::parse('2026-11-20 01:00:00');
        $end = Carbon::parse('2026-11-20 03:00:00');
        $plan->forceFill(['status' => 'SCHEDULED', 'operator_id' => $user->id, 'backup_verified' => true, 'backup_reference' => 'backup-1', 'restore_test_passed' => true, 'planned_start_at' => $start, 'planned_end_at' => $end])->save();
        $this->recordApproval($plan);
        $this->actingAs($user);
        $service = app(UpdatePlanTransition::class);
        $service->transition($plan, 'EXECUTING');
        $verification = $plan->fresh()->checklistItems()->create(['phase' => '5 Post-change Verification', 'title' => 'Verify', 'status' => 'Passed']);
        $service->transition($plan->fresh(), 'VERIFYING');
        $this->postJson(route('update-plans.transition', $plan), ['status' => 'COMPLETED'])->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $verification->update(['evidence' => 'Web and DB smoke tests passed.']);
        $done = $service->transition($plan->fresh(), 'COMPLETED');
        $this->assertNotNull($done->actual_start_at);
        $this->assertNotNull($done->actual_end_at);
        $this->assertTrue($done->planned_start_at->equalTo($start));
        $this->assertTrue($done->planned_end_at->equalTo($end));
        $this->putJson(route('update-plans.update', $done), ['impact' => 'Rewrite history'])->assertForbidden();
        $this->expectException(\DomainException::class);
        $done->update(['status' => 'DRAFT']);
    }

    public function test_failed_checklist_correction_adds_passed_row_and_preserves_failure(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->update(['status' => 'TESTING', 'test_plan' => 'Application smoke test']);
        $item = ChecklistItem::create(['update_plan_id' => $plan->id, 'phase' => '2 Pre-change Validation', 'title' => 'Test', 'status' => 'Failed']);
        $before = $item->fresh()->getRawOriginal();
        $status = route('checklist-items.status', $item);
        $transition = route('update-plans.transition', $plan);
        $this->actingAs($user)->postJson($status, ['status' => 'Pending'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($transition, ['status' => 'PENDING_APPROVAL'])->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $this->postJson($status, ['status' => 'Passed', 'correction_reason' => 'Fixed configuration'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $body = ['status' => 'Passed', 'evidence' => 'Rerun succeeded', 'correction_reason' => 'Fixed configuration'];
        $this->post($status, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($status, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('update_checklist_items', ['id' => $item->id, 'status' => 'Failed']);
        $this->assertDatabaseHas('update_checklist_items', ['update_plan_id' => $plan->id, 'status' => 'Passed', 'evidence' => 'Rerun succeeded', 'correction_of_id' => $item->id]);
        $this->assertSame(2, $plan->checklistItems()->count());
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $this->post($transition, ['status' => 'PENDING_APPROVAL'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PENDING_APPROVAL', $plan->fresh()->status);
    }

    public function test_plan_requires_impact_and_accepts_only_the_five_checklist_phases(): void
    {
        $user = $this->operator();
        $asset = Asset::create(['asset_code' => 'FIVE-PHASES', 'name' => 'Demo', 'asset_type' => 'Server']);
        $this->actingAs($user)->postJson(route('update-plans.store'), [
            'title' => 'Missing impact', 'asset_ids' => [$asset->id], 'impact' => ' ',
        ])->assertUnprocessable()->assertJsonValidationErrors('impact');
        $asset->update(['operational_status' => 'Retired']);
        $this->postJson(route('update-plans.store'), [
            'title' => 'Retired asset', 'asset_ids' => [$asset->id], 'impact' => 'Scope', 'change_type' => 'Normal',
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_ids');
        $this->assertDatabaseCount('update_plans', 0);

        $plan = $this->plan($user);
        $url = route('update-plans.checklist', $plan);
        $phases = ['1 Impact Assessment', '2 Pre-change Validation', '3 Backup and Rollback Preparation', '4 Implementation', '5 Post-change Verification'];
        foreach ($phases as $phase) {
            $this->post($url, ['phase' => $phase, 'title' => 'Evidence for '.$phase, 'is_required' => true])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->postJson($url, ['phase' => '6 Unknown phase', 'title' => 'Invalid'])->assertUnprocessable()->assertJsonValidationErrors('phase');
        $this->assertSame($phases, $plan->checklistItems()->orderBy('phase')->pluck('phase')->all());
        $this->get(route('update-plans.show', $plan))->assertOk()->assertSeeInOrder(array_map(fn ($phase) => 'Evidence for '.$phase, $phases));
    }

    public function test_only_emergency_changes_with_review_evidence_can_use_the_emergency_branch(): void
    {
        $operator = $this->operator();
        $plan = $this->plan($operator);
        $plan->update(['change_type' => 'Normal', 'status' => 'VERIFYING', 'actual_start_at' => now()->subHour()]);
        $plan->checklistItems()->create([
            'phase' => ChecklistItem::PHASES[5], 'title' => 'Ordinary verification', 'status' => 'Passed', 'evidence' => 'Service checks passed',
        ]);
        $url = route('update-plans.transition', $plan);
        $this->actingAs($operator)->postJson($url, [
            'status' => 'EMERGENCY_REVIEW', 'completion_result' => 'Reviewed the emergency authorization',
        ])->assertUnprocessable()->assertJsonValidationErrors('completion_result');
        $plan->update(['change_type' => 'Emergency']);
        $this->postJson($url, ['status' => 'EMERGENCY_REVIEW'])->assertUnprocessable()->assertJsonValidationErrors('completion_result');
        $this->post($url, ['status' => 'EMERGENCY_REVIEW', 'completion_result' => 'Reviewed the emergency authorization'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('EMERGENCY_REVIEW', $plan->fresh()->status);
        $this->assertSame('Reviewed the emergency authorization', $plan->fresh()->completion_result);
        $this->assertTrue($plan->fresh()->actual_end_at->gt($plan->fresh()->actual_start_at));
    }

    public function test_approval_resubmission_appends_new_decision_and_preserves_rejection(): void
    {
        $initiator = $this->operator();
        $reviewer = User::factory()->create();
        $reviewer->roles()->attach(Role::firstOrCreate(['name' => 'Reviewer']));
        $plan = $this->plan($initiator);
        $this->actingAs($reviewer)->post(route('update-plans.approve', $plan), ['decision' => 'REJECTED', 'comments' => 'Need revisions'])->assertRedirect();
        $old = ChangeApproval::firstOrFail();
        $snapshot = $old->only(['id', 'decision', 'comments', 'decided_at', 'created_at', 'updated_at']);
        $this->post(route('update-plans.approve', $plan), ['decision' => 'APPROVED', 'comments' => 'Revised'])->assertRedirect();
        $this->assertSame(2, $plan->approvals()->count());
        $fresh = $old->fresh();
        foreach (['id', 'decision', 'comments'] as $field) {
            $this->assertSame($snapshot[$field], $fresh->{$field});
        }
        foreach (['decided_at', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($snapshot[$field]->toDateTimeString(), $fresh->{$field}->toDateTimeString());
        }
    }

    public function test_target_version_change_invalidates_approval_and_preserves_history(): void
    {
        $operator = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);

        $this->put(route('update-plans.update', $plan), ['target_version' => '2.1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertApprovalInvalidated($approval, $operator, ['target_version' => '2.0'], ['target_version' => '2.1'], 'APPROVED');
        $auditUrl = route('audit-events.index', ['type' => ChangeApproval::class, 'id' => $approval->id]);
        $this->get(route('update-plans.show', $plan))->assertOk()->assertSee('承認失効（重要項目の変更）')->assertSee($auditUrl);
        $this->get($auditUrl)->assertOk()->assertSee('approval_invalidated')->assertSee('target_version');
    }

    public function test_operator_change_invalidates_a_scheduled_plans_approval(): void
    {
        $operator = $this->operator();
        $replacement = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);
        $this->post(route('update-plans.transition', $plan), ['status' => 'SCHEDULED'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->put(route('update-plans.update', $plan), ['operator_id' => (string) $replacement->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertApprovalInvalidated($approval, $operator, ['operator_id' => $operator->id], ['operator_id' => $replacement->id], 'SCHEDULED');
    }

    public function test_impact_change_invalidates_approval_for_a_plan_on_hold(): void
    {
        $operator = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);
        $this->post(route('update-plans.transition', $plan), ['status' => 'BLOCKED'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->put(route('update-plans.update', $plan), ['impact' => 'Customer checkout unavailable'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertApprovalInvalidated($approval, $operator, ['impact' => 'User impact assessed'], ['impact' => 'Customer checkout unavailable'], 'BLOCKED');
    }

    public function test_critical_edits_before_approval_keep_the_testing_state(): void
    {
        $operator = $this->operator();
        $plan = $this->plan($operator);
        $plan->update(['status' => 'TESTING']);

        $this->actingAs($operator)->put(route('update-plans.update', $plan), ['target_version' => '2.1', 'impact' => 'Revised scope'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'target_version' => '2.1', 'impact' => 'Revised scope', 'status' => 'TESTING']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'approval_invalidated']);
    }

    public function test_invalidated_approval_cannot_authorize_approval_or_scheduling(): void
    {
        $operator = $this->operator();
        [$plan] = $this->approvedPlan($operator);
        $this->put(route('update-plans.update', $plan), ['target_version' => '2.1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $url = route('update-plans.transition', $plan);

        $this->postJson($url, ['status' => 'APPROVED'])->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->postJson($url, ['status' => 'SCHEDULED'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('PENDING_APPROVAL', $plan->fresh()->status);

        $plan->fresh()->update(['status' => 'APPROVED']);
        $this->postJson($url, ['status' => 'SCHEDULED'])->assertUnprocessable()->assertJsonValidationErrors('approval');
        $this->assertSame('APPROVED', $plan->fresh()->status);
    }

    public function test_new_approval_allows_scheduling_and_execution_after_invalidation(): void
    {
        $operator = $this->operator();
        $executor = $this->operator();
        [$plan, $oldApproval] = $this->approvedPlan($operator);
        $this->put(route('update-plans.update', $plan), ['target_version' => '2.1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $newApproval = $this->recordApproval($plan);
        $url = route('update-plans.transition', $plan);
        $this->actingAs($operator)->post($url, ['status' => 'APPROVED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, ['status' => 'SCHEDULED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($executor)->post($url, ['status' => 'EXECUTING'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $plan->approvals()->count());
        $this->assertSame($newApproval->id, $plan->validApprovals()->sole()->id);
        $this->assertModelExists($oldApproval);
        $this->assertDatabaseHas('update_plans', [
            'id' => $plan->id, 'status' => 'EXECUTING', 'target_version' => '2.1',
            'approver_id' => $newApproval->approver_id, 'operator_id' => $operator->id,
        ]);
        $this->assertDatabaseHas('work_records', ['update_plan_id' => $plan->id, 'user_id' => $executor->id, 'summary' => 'Update plan EXECUTING']);
    }

    public function test_changing_backup_and_restore_flags_invalidates_approval(): void
    {
        $operator = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);

        $this->put(route('update-plans.update', $plan), ['backup_verified' => '0', 'restore_test_passed' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertApprovalInvalidated($approval, $operator,
            ['backup_verified' => true, 'restore_test_passed' => true],
            ['backup_verified' => false, 'restore_test_passed' => false],
            'APPROVED',
        );
    }

    public function test_maintenance_window_edits_and_unchanged_critical_values_preserve_approval(): void
    {
        $operator = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);

        $this->put(route('update-plans.update', $plan), [
            'planned_end_at' => '2026-11-20 04:00:00', 'operator_id' => (string) $operator->id,
            'target_version' => '2.0', 'backup_verified' => '1', 'restore_test_passed' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'APPROVED', 'planned_end_at' => '2026-11-20 04:00:00']);
        $this->assertSame($approval->id, $plan->validApprovals()->sole()->id);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'approval_invalidated']);
    }

    public function test_approval_rejects_self_approval_in_policy_and_database(): void
    {
        $initiator = $this->operator();
        $plan = $this->plan($initiator);
        $initiator->roles()->attach(Role::firstOrCreate(['name' => 'Reviewer']));
        $this->actingAs($initiator)->post(route('update-plans.approve', $plan), ['decision' => 'APPROVED'])->assertForbidden();
        $executor = User::factory()->create();
        $other = User::factory()->create();
        foreach ([['initiated_by' => $initiator->id, 'executed_by' => $executor->id], ['initiated_by' => $other->id, 'executed_by' => $initiator->id]] as $ids) {
            try {
                DB::table('change_approvals')->insert(['update_plan_id' => $plan->id, ...$ids, 'approver_id' => $initiator->id, 'decision' => 'APPROVED', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $this->fail('The database CHECK must reject initiator/executor self approval.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('chk_change_approvals_independent', $exception->getMessage());
                $this->assertDatabaseCount('change_approvals', 0);
            }
        }
    }

    public function test_ui05_status_and_time_groups_link_same_plan_as_index(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['planned_start_at' => Carbon::parse('2026-11-21 02:00:00'), 'planned_end_at' => Carbon::parse('2026-11-21 04:00:00')])->save();
        $this->actingAs($user);
        foreach (['/update-plans', '/update-plans?view=status', '/update-plans?view=time'] as $url) {
            $this->get($url)->assertOk()->assertSee($plan->plan_code)->assertSee(route('update-plans.show', $plan));
        }
        $this->get('/update-plans?view=status')->assertSee('DRAFT');
        $this->get('/update-plans?view=time')->assertSee('2026-11-21');
        $this->get(route('update-plans.show', $plan))->assertSeeInOrder(['AI調査テンプレート（DEMO）', '調査質問の例', '出力形式', '人が内容を確認しました']);
    }

    /** @return array{UpdatePlan, ChangeApproval} */
    private function executingPlan(User $operator): array
    {
        [$plan, $approval] = $this->approvedPlan($operator);
        $executor = $this->operator();
        $this->actingAs($operator)->post(route('update-plans.transition', $plan), ['status' => 'SCHEDULED'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($executor)->post(route('update-plans.transition', $plan), ['status' => 'EXECUTING'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($operator);

        return [$plan->refresh(), $approval];
    }

    private function transitionAs(UpdatePlan $plan, string $status, User $actor): void
    {
        $this->actingAs($actor)->post(route('update-plans.transition', $plan), ['status' => $status])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_r1_t1_critical_change_is_rejected_while_executing(): void
    {
        $operator = $this->operator();
        [$plan] = $this->executingPlan($operator);
        $this->assertNotNull($plan->actual_start_at);

        $this->actingAs($operator)->putJson(route('update-plans.update', $plan), ['target_version' => '9.9'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_version');

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'EXECUTING', 'target_version' => '2.0']);
    }

    public function test_r1_t2_critical_change_is_rejected_while_verifying(): void
    {
        $operator = $this->operator();
        [$plan] = $this->executingPlan($operator);
        $this->transitionAs($plan, 'VERIFYING', $operator);

        $this->actingAs($operator)->putJson(route('update-plans.update', $plan), ['impact' => 'Rewritten during verification'])
            ->assertUnprocessable()->assertJsonValidationErrors('impact');

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'VERIFYING', 'impact' => 'User impact assessed']);
    }

    public function test_r1_t3_critical_change_is_rejected_in_failure_and_rollback_states(): void
    {
        $operator = $this->operator();

        [$failed] = $this->executingPlan($operator);
        $this->transitionAs($failed, 'FAILED', $operator);
        $this->actingAs($operator)->putJson(route('update-plans.update', $failed), ['target_version' => '9.9'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_version');
        $this->assertSame('FAILED', $failed->fresh()->status);

        [$required] = $this->executingPlan($operator);
        $this->transitionAs($required, 'ROLLBACK_REQUIRED', $operator);
        $this->actingAs($operator)->putJson(route('update-plans.update', $required), ['rollback_plan' => 'Rewritten rollback'])
            ->assertUnprocessable()->assertJsonValidationErrors('rollback_plan');
        $this->assertSame('ROLLBACK_REQUIRED', $required->fresh()->status);

        [$rollingBack] = $this->executingPlan($operator);
        $this->transitionAs($rollingBack, 'ROLLBACK_REQUIRED', $operator);
        $this->transitionAs($rollingBack, 'ROLLING_BACK', $operator);
        $this->actingAs($operator)->putJson(route('update-plans.update', $rollingBack), ['operator_id' => (string) $this->operator()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('operator_id');
        $this->assertSame('ROLLING_BACK', $rollingBack->fresh()->status);
    }

    public function test_r1_t4_critical_change_is_rejected_when_blocked_after_execution_started(): void
    {
        $operator = $this->operator();
        [$plan] = $this->executingPlan($operator);
        $this->transitionAs($plan, 'BLOCKED', $operator);
        $plan->refresh();
        $this->assertSame('EXECUTING', $plan->blocked_from_status);
        $this->assertNotNull($plan->actual_start_at);

        $this->actingAs($operator)->putJson(route('update-plans.update', $plan), ['target_version' => '9.9'])
            ->assertUnprocessable()->assertJsonValidationErrors('target_version');

        $this->assertDatabaseHas('update_plans', [
            'id' => $plan->id, 'status' => 'BLOCKED', 'blocked_from_status' => 'EXECUTING', 'target_version' => '2.0',
        ]);
    }

    public function test_r1_t5_critical_change_before_execution_still_invalidates_approval_when_blocked(): void
    {
        $operator = $this->operator();
        [$plan, $approval] = $this->approvedPlan($operator);
        $this->post(route('update-plans.transition', $plan), ['status' => 'BLOCKED'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $plan->refresh();
        $this->assertSame('APPROVED', $plan->blocked_from_status);
        $this->assertNull($plan->actual_start_at);

        $this->put(route('update-plans.update', $plan), ['target_version' => '2.1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertApprovalInvalidated($approval, $operator, ['target_version' => '2.0'], ['target_version' => '2.1'], 'BLOCKED');
    }

    public function test_r1_t6_rejected_critical_change_has_no_database_side_effects(): void
    {
        $operator = $this->operator();
        [$plan] = $this->executingPlan($operator);
        $planRow = $plan->fresh()->getRawOriginal();
        $approvalCount = ChangeApproval::where('update_plan_id', $plan->id)->count();
        $auditCount = AuditEvent::count();
        $workRecordCount = WorkRecord::where('update_plan_id', $plan->id)->count();

        $this->actingAs($operator)->putJson(route('update-plans.update', $plan), [
            'target_version' => '9.9', 'planned_end_at' => '2026-11-21 05:00:00',
        ])->assertUnprocessable();

        $this->assertSame($planRow, $plan->fresh()->getRawOriginal());
        $this->assertSame($approvalCount, ChangeApproval::where('update_plan_id', $plan->id)->count());
        $this->assertSame($auditCount, AuditEvent::count());
        $this->assertSame($workRecordCount, WorkRecord::where('update_plan_id', $plan->id)->count());
    }
}
