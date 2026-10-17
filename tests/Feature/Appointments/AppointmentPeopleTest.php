<?php

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| Finding a person to book for (5.5b): those who book (not only those who see cardholders) find people by the
| cardholder list's search, an exact employee number first; only who they are and their family members come.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    ($this->inTenant)(function () {
        $holder = fn (array $values) => Cardholder::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'secret@example.com', ...$values]);
        $this->karim = $holder(['full_name' => 'Karim Hasan', 'full_name_local' => 'করিম হাসান', 'employee_no' => 'E-77']);
        $holder(['full_name' => 'Abdul E-77 Karim', 'employee_no' => 'E-770']);
        FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->karim->id, 'name' => 'Ayesha Hasan', 'relation' => 'daughter']);
        foreach (range(1, 12) as $n) {
            $holder(['full_name' => "Many Person {$n}", 'employee_no' => "M-{$n}"]);
        }
    });
    $this->find = fn (string $search, array $headers = []) => $this->getJson('/api/v1/appointments/people?search='.urlencode($search), $headers);
});

it('finds people to book for by name, local name or employee number, an exact number first, with their family', function () {
    Sanctum::actingAs($this->owner);

    $found = ($this->find)('E-77')->assertOk()->json('data');
    expect(array_column($found, 'name'))->toBe(['Karim Hasan', 'Abdul E-77 Karim'])
        ->and($found[0])->toBe([
            'id' => $this->karim->public_id, 'name' => 'Karim Hasan', 'name_local' => 'করিম হাসান', 'employee_no' => 'E-77', 'status' => $this->karim->status->value,
            'family_members' => [['id' => ($this->inTenant)(fn () => FamilyMember::query()->sole()->public_id), 'name' => 'Ayesha Hasan', 'relation' => 'daughter']],
        ]);
    expect(array_column(($this->find)('করিম')->json('data'), 'employee_no'))->toBe(['E-77'])
        ->and(($this->find)('Many Person')->json('data'))->toHaveCount(10)
        ->and(($this->find)('Nobody here')->json('data'))->toBe([]);
});

it('lets those who book find people without seeing cardholders, and nobody else', function () {
    $operator = tenantUser($this->tenant, 'Operator');
    // Booking, not the cardholders' records
    ($this->inTenant)(fn () => Role::findByName('Operator')->syncPermissions(['appointments.view', 'appointments.book']));
    Sanctum::actingAs($operator->fresh());
    ($this->find)('Karim')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/cardholders?filter[search]=Karim')->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    ($this->find)('Karim')->assertForbidden();

    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    expect(($this->find)('Karim')->assertOk()->json('data'))->toBe([]);
});

it('asks for two characters at least, in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);

    ($this->find)('K', ['Accept-Language' => 'bn'])->assertUnprocessable()->assertJsonPath('errors.search', ['খোঁজ কমপক্ষে ২ অক্ষরের হতে হবে।']);
    $this->getJson('/api/v1/appointments/people')->assertUnprocessable()->assertJsonValidationErrors('search');
});
