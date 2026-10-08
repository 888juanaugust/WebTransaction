<?php

namespace Tests\Feature;

use App\Filament\Resources\CashBank\CashPayments\Pages\ListCashPayments;
use Livewire\Livewire;
use Tests\TestCase;

/** DESIGN.md: an empty list names the record; a narrowed one says so and how to see more. */
class EmptyStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAsAdmin();
    }

    private function records(): string
    {
        return mb_strtolower(ListCashPayments::getResource()::getPluralModelLabel());
    }

    public function test_an_empty_list_names_its_records(): void
    {
        Livewire::test(ListCashPayments::class)
            ->assertSee(__('No :records yet', ['records' => $this->records()]))
            ->assertDontSee(__('Change the search, the filters or the tab to see more.'));
    }

    public function test_a_search_that_finds_nothing_says_how_to_see_more(): void
    {
        Livewire::test(ListCashPayments::class)
            ->searchTable('nothing like this')
            ->assertSee(__('No :records match', ['records' => $this->records()]))
            ->assertSee(__('Change the search, the filters or the tab to see more.'));
    }
}
