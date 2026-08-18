<?php

namespace Tests\Feature;

use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Models\User;
use App\Services\ShiftCashReconciliationService;
use App\Services\ShiftVarianceResolutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftVarianceBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_desk_with_one_pending_review_variance_receives_banner_data(): void
    {
        $fd = $this->frontDesk('a');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('cash_variance_banner')
                ->where('cash_variance_banner.count', 1)
                ->where('cash_variance_banner.shift.shift_id', $shift->id)
                ->where('cash_variance_banner.shift.overall_status', ShiftCashReconciliationService::STATUS_PENDING_REVIEW)
                ->where('cash_variance_banner.view_label', 'View Variance')
                ->where('cash_variance_banner.shift.awaiting_admin_review', false)
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 500.0)
                ->where('cash_variance_banner.total_remaining_overage', fn ($value) => (float) $value === 0.0)
                ->where('cash_variance_banner.shift.drawers.0.kind', 'Shortage')
                ->where('cash_variance_banner.shift.drawers.0.remaining', fn ($value) => (float) $value === 500.0)
                ->where('cash_variance_banner.view_url', fn ($url) => str_contains((string) $url, '/shifts/'.$shift->id.'/report')
                    && str_contains((string) $url, 'tab=variance'))
            );

        $this->actingAs($fd)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner.count', 1)
            ->assertJsonPath('cash_variance_banner.shift.shift_id', $shift->id);
    }

    public function test_banner_closed_at_converts_utc_storage_to_asia_manila_display(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 13:19:34', 'UTC'));

        try {
            $fd = $this->frontDesk('tz');
            $shift = $this->closeShortage($fd);

            $this->assertSame('UTC', config('app.timezone'));
            $this->assertSame(
                '2026-08-18 13:19:34',
                $shift->ended_at->copy()->utc()->format('Y-m-d H:i:s')
            );

            $this->actingAs($fd)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('cash_variance_banner.shift.closed_at', '2026-08-18T13:19:34Z')
                    ->where('cash_variance_banner.shift.closed_at_display', '8/18/2026, 9:19:34 PM')
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_banner_survives_fresh_session_when_pending_review_has_no_resolution(): void
    {
        $fd = $this->frontDesk('sess');
        $shift = $this->closeShortageEightyFive($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
                ->where('cash_variance_banner.shift.shift_id', $shift->id)
                ->where('cash_variance_banner.shift.overall_status', ShiftCashReconciliationService::STATUS_PENDING_REVIEW)
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 85.0)
            );

        $this->post(route('logout'));

        $this->actingAs($fd)
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
                ->where('cash_variance_banner.shift.shift_id', $shift->id)
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 85.0)
            );

        $this->actingAs($fd)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner.count', 1)
            ->assertJsonPath('cash_variance_banner.shift.shift_id', $shift->id);
    }

    public function test_eighty_five_shortage_banner_follows_approved_remaining_only(): void
    {
        $fd = $this->frontDesk('flow85');
        $admin = $this->admin('flow85');
        $shift = $this->closeShortageEightyFive($fd);

        $this->actingAs($fd)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cash_variance_banner.count', 1)
            ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 85.0)
        );

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(85, 'Shortage recovered'));
        $this->actingAs($fd)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cash_variance_banner.count', 1)
            ->where('cash_variance_banner.shift.awaiting_admin_review', true)
            ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 85.0)
        );

        $first = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.reject', $first), ['review_notes' => 'Split the recovery']);
        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(50, 'Partial cash found'));
        $partial = ShiftVarianceResolution::query()->where('status', 'submitted')->firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $partial), [
            'review_notes' => 'Partial accepted',
            'recovery_destination' => 'office_safe',
        ]);

        $this->actingAs($fd)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cash_variance_banner.count', 1)
            ->where('cash_variance_banner.shift.overall_status', ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED)
            ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 35.0)
        );

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(35, 'Final cash found'));
        $final = ShiftVarianceResolution::query()->where('status', 'submitted')->orderByDesc('id')->firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $final), [
            'review_notes' => 'Cleared',
            'recovery_destination' => 'office_safe',
        ]);

        $this->assertSame(ShiftVarianceResolutionService::STATUS_RESOLVED, $shift->fresh()->variance_status);

        $this->actingAs($fd)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cash_variance_banner', null)
        );
    }

    public function test_partially_resolved_variance_keeps_banner_with_remaining_amount(): void
    {
        $fd = $this->frontDesk('b');
        $admin = $this->admin('b');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(300, 'Partial replenishment'));
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Partial accepted']);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
                ->where('cash_variance_banner.shift.overall_status', ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED)
                ->where('cash_variance_banner.shift.drawers.0.resolved_amount', fn ($value) => (float) $value === 300.0)
                ->where('cash_variance_banner.shift.drawers.0.remaining', fn ($value) => (float) $value === 200.0)
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 200.0)
            );
    }

    public function test_resolved_variance_has_no_banner(): void
    {
        $fd = $this->frontDesk('c');
        $admin = $this->admin('c');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(500, 'Full replenishment'));
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Cleared']);

        $this->assertSame(ShiftVarianceResolutionService::STATUS_RESOLVED, $shift->fresh()->variance_status);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );
    }

    public function test_balanced_shift_has_no_banner(): void
    {
        $fd = $this->frontDesk('d');
        $this->closeBalanced($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );
    }

    public function test_multiple_unresolved_shifts_summarize_count_and_separate_totals(): void
    {
        $fd = $this->frontDesk('e');
        $this->closeShortage($fd);
        $this->closeShortage($fd);
        $this->closeOverage($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 3)
                ->where('cash_variance_banner.shift', null)
                ->where('cash_variance_banner.view_label', 'View My Variances')
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 1000.0)
                ->where('cash_variance_banner.total_remaining_overage', fn ($value) => (float) $value === 300.0)
                ->where('cash_variance_banner.view_url', fn ($url) => str_contains((string) $url, '/shifts')
                    && ! str_contains((string) $url, 'variances'))
            );
    }

    public function test_shortage_and_overage_are_not_netted_together(): void
    {
        $fd = $this->frontDesk('f');
        $this->closeOffsettingDrawers($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
                ->missing('cash_variance_banner.net')
                ->missing('cash_variance_banner.net_variance')
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 500.0)
                ->where('cash_variance_banner.total_remaining_overage', fn ($value) => (float) $value === 500.0)
                ->where('cash_variance_banner.shift.drawers.0.kind', 'Shortage')
                ->where('cash_variance_banner.shift.drawers.1.kind', 'Overage')
            );
    }

    public function test_front_desk_cannot_receive_another_employees_variance(): void
    {
        $owner = $this->frontDesk('g1');
        $other = $this->frontDesk('g2');
        $shift = $this->closeShortage($owner);

        $this->actingAs($other)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );

        $this->actingAs($other)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner', null);

        $this->actingAs($other)
            ->get(route('shifts.report', $shift->id))
            ->assertForbidden();
    }

    public function test_housekeeping_gets_no_variance_banner_data(): void
    {
        $fd = $this->frontDesk('h');
        $this->closeShortage($fd);
        $hk = User::factory()->create([
            'role' => 'housekeeping',
            'username' => 'var_hk_'.substr(uniqid(), -6),
        ]);

        $this->actingAs($hk)
            ->get(route('rooms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );

        $this->actingAs($hk)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner', null);
    }

    public function test_admin_does_not_receive_personal_front_desk_banner_payload(): void
    {
        $fd = $this->frontDesk('i');
        $admin = $this->admin('i');
        $this->closeShortage($fd);
        $this->closeShortage($admin);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );

        $this->actingAs($admin)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner', null);
    }

    public function test_submitted_but_unapproved_resolution_does_not_reduce_remaining(): void
    {
        $fd = $this->frontDesk('j');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(500, 'Please accept'));

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
                ->where('cash_variance_banner.shift.overall_status', ShiftCashReconciliationService::STATUS_PENDING_REVIEW)
                ->where('cash_variance_banner.shift.awaiting_admin_review', true)
                ->where('cash_variance_banner.shift.drawers.0.resolved_amount', fn ($value) => (float) $value === 0.0)
                ->where('cash_variance_banner.shift.drawers.0.remaining', fn ($value) => (float) $value === 500.0)
                ->where('cash_variance_banner.total_remaining_shortage', fn ($value) => (float) $value === 500.0)
            );
    }

    public function test_banner_disappears_after_final_admin_approval_on_subsequent_request(): void
    {
        $fd = $this->frontDesk('k');
        $admin = $this->admin('k');
        $shift = $this->closeShortage($fd);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner.count', 1)
            );

        $this->actingAs($fd)->post(route('shifts.variances.store', $shift), $this->submitPayload(500, 'Final replenishment'));
        $resolution = ShiftVarianceResolution::firstOrFail();
        $this->actingAs($admin)->post(route('shifts.variances.approve', $resolution), ['review_notes' => 'Cleared']);

        $this->assertSame(ShiftVarianceResolutionService::STATUS_RESOLVED, $shift->fresh()->variance_status);

        $this->actingAs($fd)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cash_variance_banner', null)
            );

        $this->actingAs($fd)
            ->getJson(route('api.notifications'))
            ->assertOk()
            ->assertJsonPath('cash_variance_banner', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitPayload(float $amount, string $notes): array
    {
        return [
            'drawer' => 'room',
            'resolution_type' => 'shortage_recovery',
            'amount' => $amount,
            'notes' => $notes,
        ];
    }

    private function closeShortage(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short five hundred.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function closeShortageEightyFive(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 14915,
            'closing_denominations' => ['1000' => 14, '500' => 1, '100' => 4, '10' => 1, '5' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Short eighty five.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function closeOverage(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 15300,
            'closing_denominations' => ['1000' => 15, '200' => 1, '100' => 1],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Over three hundred.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function closeBalanced(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 15000,
            'closing_denominations' => ['1000' => 15],
            'closing_cash_minibar' => 0,
            'closing_denominations_minibar' => [],
            'notes' => 'Balanced close.',
        ])->assertRedirect();

        $this->assertSame(ShiftCashReconciliationService::STATUS_BALANCED, $shift->fresh()->variance_status);

        return $shift->fresh();
    }

    private function closeOffsettingDrawers(User $user): ShiftSession
    {
        $shift = $this->openShift($user, 15000);
        $this->actingAs($user)->post(route('shifts.end'), [
            'closing_cash' => 14500,
            'closing_denominations' => ['1000' => 14, '500' => 1],
            'closing_cash_minibar' => 500,
            'closing_denominations_minibar' => ['500' => 1],
            'notes' => 'Rooms short, minibar over. Do not net.',
        ])->assertRedirect();

        return $shift->fresh();
    }

    private function openShift(User $user, float $openingCash): ShiftSession
    {
        return ShiftSession::create([
            'user_id' => $user->id,
            'active_register_key' => ShiftSession::MAIN_REGISTER_KEY,
            'shift_code' => 'morning',
            'started_at' => now()->subHour(),
            'opening_cash' => $openingCash,
            'opening_cash_minibar' => 0,
        ]);
    }

    private function frontDesk(string $suffix): User
    {
        return User::factory()->create([
            'role' => 'front_desk',
            'username' => 'ban_fd_'.$suffix.'_'.substr(uniqid(), -6),
            'full_name' => 'Banner Desk '.$suffix,
        ]);
    }

    private function admin(string $suffix): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'username' => 'ban_admin_'.$suffix.'_'.substr(uniqid(), -6),
        ]);
    }
}
