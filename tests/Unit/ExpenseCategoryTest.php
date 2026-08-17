<?php

namespace Tests\Unit;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_name_trims_collapses_whitespace_and_title_cases(): void
    {
        $this->assertSame('Salary', ExpenseCategory::normalizeName('salary'));
        $this->assertSame('Salary', ExpenseCategory::normalizeName('SALARY'));
        $this->assertSame('Salary', ExpenseCategory::normalizeName(' Salary '));
        $this->assertSame('Food & Beverage', ExpenseCategory::normalizeName('  food   &   beverage '));
        $this->assertSame('', ExpenseCategory::normalizeName('   '));
    }

    public function test_find_or_create_from_name_reuses_case_insensitive_duplicates(): void
    {
        $first = ExpenseCategory::findOrCreateFromName('cleaning');
        $second = ExpenseCategory::findOrCreateFromName(' CLEANING ');
        $third = ExpenseCategory::findOrCreateFromName('Cleaning');

        $this->assertTrue($first->is($second));
        $this->assertTrue($first->is($third));
        $this->assertSame('Cleaning', $first->name);
        $this->assertSame(1, ExpenseCategory::query()->whereRaw('LOWER(name) = ?', ['cleaning'])->count());
    }

    public function test_seeded_defaults_exist_and_are_not_duplicated(): void
    {
        ExpenseCategory::ensureDefaults();
        ExpenseCategory::ensureDefaults();

        foreach (ExpenseCategory::DEFAULT_NAMES as $name) {
            $this->assertSame(
                1,
                ExpenseCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->count(),
                "Expected a single {$name} category"
            );
        }
    }
}
