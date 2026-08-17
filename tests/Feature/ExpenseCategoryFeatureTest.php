<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpenseController;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseCategoryFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function createExpense(User $user, array $overrides = []): Expense
    {
        $category = $overrides['category'] ?? ExpenseCategory::findOrCreateFromName('Supplies');
        unset($overrides['category']);

        return Expense::create(array_merge([
            'expense_date' => '2026-08-16',
            'amount' => 100.00,
            'cash_drawer' => 'room',
            'notes' => 'Office supplies',
            'expense_category_id' => $category->id,
            'recorded_by' => $user->id,
        ], $overrides));
    }

    public function test_legacy_expenses_without_a_category_are_marked_uncategorized(): void
    {
        $admin = $this->admin();

        $expense = Expense::create([
            'expense_date' => '2026-01-01',
            'amount' => 50.00,
            'cash_drawer' => 'room',
            'notes' => 'Legacy expense',
            'recorded_by' => $admin->id,
        ]);

        $this->assertSame(
            ExpenseCategory::UNCATEGORIZED,
            $expense->fresh()->category->name
        );
    }

    public function test_store_requires_a_category(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 250.00,
            'cash_drawer' => 'room',
            'notes' => 'Missing category',
        ]);

        $response->assertSessionHasErrors(['category']);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_store_uses_an_existing_category(): void
    {
        $admin = $this->admin();
        $salary = ExpenseCategory::findOrCreateFromName('Salary');

        $response = $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 1500.00,
            'cash_drawer' => 'room',
            'category' => 'salary',
            'notes' => 'August payroll',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('expenses', [
            'notes' => 'August payroll',
            'expense_category_id' => $salary->id,
        ]);
        $this->assertSame(1, ExpenseCategory::query()->whereRaw('LOWER(name) = ?', ['salary'])->count());
    }

    public function test_store_creates_a_new_category_from_typed_name(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 80.00,
            'cash_drawer' => 'minibar',
            'category' => '  pest  control ',
            'notes' => 'Monthly pest control',
        ]);

        $response->assertRedirect();

        $category = ExpenseCategory::findByNormalizedName('Pest Control');
        $this->assertNotNull($category);
        $this->assertSame('Pest Control', $category->name);
        $this->assertDatabaseHas('expenses', [
            'notes' => 'Monthly pest control',
            'expense_category_id' => $category->id,
        ]);
    }

    public function test_duplicate_category_names_are_reused_regardless_of_case_or_spacing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 10.00,
            'cash_drawer' => 'room',
            'category' => 'salary',
            'notes' => 'One',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 20.00,
            'cash_drawer' => 'room',
            'category' => ' SALARY ',
            'notes' => 'Two',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('expenses.store'), [
            'expense_date' => '2026-08-16',
            'amount' => 30.00,
            'cash_drawer' => 'room',
            'category' => 'Salary',
            'notes' => 'Three',
        ])->assertRedirect();

        $this->assertSame(1, ExpenseCategory::query()->whereRaw('LOWER(name) = ?', ['salary'])->count());
        $this->assertEquals(3, Expense::query()->where('expense_category_id', ExpenseCategory::findByNormalizedName('Salary')->id)->count());
    }

    public function test_update_changes_the_expense_category(): void
    {
        $admin = $this->admin();
        $expense = $this->createExpense($admin, [
            'category' => ExpenseCategory::findOrCreateFromName('Supplies'),
        ]);

        $response = $this->actingAs($admin)->post(route('expenses.update', $expense), [
            'expense_date' => '2026-08-17',
            'amount' => 175.00,
            'cash_drawer' => 'minibar',
            'category' => 'Utilities',
            'notes' => 'Updated bill',
        ]);

        $response->assertRedirect();
        $expense->refresh();
        $this->assertSame('Utilities', $expense->category->name);
        $this->assertSame('Updated bill', $expense->notes);
    }

    public function test_index_filters_search_and_summaries_by_category(): void
    {
        $admin = $this->admin();
        $salary = ExpenseCategory::findOrCreateFromName('Salary');
        $supplies = ExpenseCategory::findOrCreateFromName('Supplies');

        $this->createExpense($admin, [
            'category' => $salary,
            'amount' => 200.00,
            'notes' => 'Payroll',
        ]);
        $this->createExpense($admin, [
            'category' => $supplies,
            'amount' => 50.00,
            'notes' => 'Paper',
        ]);

        $this->actingAs($admin)
            ->get(route('expenses.index', ['category' => $salary->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Expenses/Index')
                ->where('summary.total_count', 1)
                ->where('summary.total_amount', '200.00')
                ->has('expenses.data', 1)
                ->where('expenses.data.0.category.name', 'Salary')
                ->where('filters.category', (string) $salary->id)
            );

        $this->actingAs($admin)
            ->get(route('expenses.index', ['search' => 'Salary']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('expenses.data', 1)
                ->where('expenses.data.0.notes', 'Payroll')
            );
    }

    public function test_index_can_sort_by_category(): void
    {
        $admin = $this->admin();
        $this->createExpense($admin, ['category' => ExpenseCategory::findOrCreateFromName('Utilities')]);
        $this->createExpense($admin, ['category' => ExpenseCategory::findOrCreateFromName('Marketing')]);

        $this->actingAs($admin)
            ->get(route('expenses.index', ['sort_by' => 'category', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sortBy', 'category')
                ->where('expenses.data.0.category.name', 'Marketing')
            );
    }

    public function test_export_rows_include_category_column_filter_and_metadata(): void
    {
        $admin = $this->admin();
        $salary = ExpenseCategory::findOrCreateFromName('Salary');
        $supplies = ExpenseCategory::findOrCreateFromName('Supplies');

        $included = $this->createExpense($admin, [
            'category' => $salary,
            'amount' => 300.00,
            'notes' => 'Payroll export',
        ]);
        $this->createExpense($admin, [
            'category' => $supplies,
            'amount' => 40.00,
            'notes' => 'Should be excluded',
        ]);

        $request = Request::create('/expenses-export', 'GET', [
            'category' => $salary->id,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'search' => 'Payroll',
        ]);
        $request->setUserResolver(fn () => $admin);

        $expenses = Expense::query()
            ->with(['user:id,full_name', 'category:id,name'])
            ->where('expense_category_id', $salary->id)
            ->get();

        $rows = (new ExpenseController)->buildExportRows($expenses, $admin, $request);

        $this->assertContains(['Category:', 'Salary'], $rows);
        $this->assertContains(['Period:', '2026-08-01 to 2026-08-31'], $rows);
        $this->assertTrue(collect($rows)->contains(fn ($row) => ($row[0] ?? null) === 'ID' && end($row) === 'Category'));
        $this->assertTrue(collect($rows)->contains(fn ($row) => ($row[0] ?? null) === $included->id && end($row) === 'Salary'));
        $this->assertFalse(collect($rows)->contains(fn ($row) => in_array('Should be excluded', $row, true)));
    }

    public function test_housekeeping_cannot_access_expenses(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);

        $this->actingAs($housekeeper)
            ->get(route('expenses.index'))
            ->assertForbidden();
    }
}
