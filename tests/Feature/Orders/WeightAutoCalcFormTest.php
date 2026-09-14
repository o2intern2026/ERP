<?php

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 2026-09-14 lead feedback: on the order forms the goods lines and the declared packages asked for the same weights twice in
 * different units (单件重量 vs 整行合计). Both forms now carry a 单件重量 helper per goods line (converted through 箱数, never
 * submitted), live totals under both tables, and a 按货物明细自动生成申报包裹 switch (default on) whose JS mirrors the goods
 * lines into the declared packages. PHPUnit cannot run the JS; this pins the markup and strings the JS relies on.
 */
class WeightAutoCalcFormTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_both_order_forms_carry_the_unit_weight_helper_totals_and_the_follow_lines_switch(): void
    {
        $client = $this->client();
        $staff = $this->staff('customer_service');
        $portalUser = $this->clientUser($client);

        foreach ([[$staff, route('orders.create')], [$portalUser, route('portal.orders.create')]] as [$actor, $url]) {
            $page = $this->actingAs($actor)->get($url)->assertOk();

            // Goods line: the helper has no name (never submitted); the line total keeps its name and gains a class the JS reads.
            $page->assertSee('class="unit-weight"', false)->assertSee('class="line-weight" name="lines[0][actual_weight_kg]"', false)
                ->assertSee('class="line-weight" name="lines[__INDEX__][actual_weight_kg]"', false)
                ->assertSee(__('orders.lines.unit_weight'))->assertSee(__('orders.lines.hint'))
                ->assertSee('id="goods-lines-total"', false);
            $this->assertStringNotContainsString('name="lines[0][unit_weight', $page->getContent());

            // Declared packages: the switch (checked by default), its hint and the totals footer.
            $page->assertSee('id="packages-follow-lines" name="packages_follow_lines" value="1" checked', false)
                ->assertSee(__('orders.pickup.follow_lines'))->assertSee(__('orders.pickup.follow_hint'))
                ->assertSee('id="declared-packages-total"', false)
                ->assertSee('const mirror = () =>', false); // the mirroring JS ships with the goods-lines partial
        }
    }

    public function test_the_switch_state_survives_a_failed_submit(): void
    {
        $client = $this->client();
        $portalUser = $this->clientUser($client);

        $page = $this->actingAs($portalUser)->from(route('portal.orders.create'))->post(route('portal.orders.store'), [
            'order_type' => 'pickup_deliver', 'packages_follow_lines' => '0',
        ])->assertRedirect(route('portal.orders.create'));
        $this->actingAs($portalUser)->get(route('portal.orders.create'))->assertOk()
            ->assertSee('id="packages-follow-lines"', false)
            ->assertDontSee('id="packages-follow-lines" name="packages_follow_lines" value="1" checked', false);
    }
}
