<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Validation messages must be sentences, not translation keys.
 *
 * `APP_LOCALE` and `APP_FALLBACK_LOCALE` are both `id`, so nothing falls back
 * to Laravel's built-in English file. Until `lang/id/validation.php` existed,
 * every validation message in the application rendered as its own key: a
 * member of staff typing a duplicate email was shown the literal string
 * "validation.unique".
 *
 * Most rules never surfaced, because the browser's own required/type checks
 * fire before a form is ever submitted. The ones that always reach the server
 * are the ones a browser cannot check — `unique`, `exists` — which is why the
 * bug lived on every resource with a unique column without being obvious.
 */
class ValidationMessagesAreIndonesianTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rules that reach the server on a form somebody uses every week.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    public static function rules(): array
    {
        return [
            ['validation.required', []],
            ['validation.email', []],
            ['validation.unique', []],
            ['validation.exists', []],
            ['validation.min.string', ['min' => 12]],
            ['validation.max.string', ['max' => 190]],
            ['validation.numeric', []],
            ['validation.integer', []],
            ['validation.date', []],
            ['validation.confirmed', []],
            ['validation.current_password', []],
        ];
    }

    /** @param  array<string, mixed>  $replace */
    #[DataProvider('rules')]
    public function test_a_rule_resolves_to_a_sentence(string $key, array $replace): void
    {
        $message = trans($key, $replace + ['attribute' => 'Email']);

        $this->assertNotSame($key, $message, "{$key} still renders as its own key");
        $this->assertStringEndsWith('.', $message, "{$key} is not a sentence");

        /*
         * Laravel snake-cases the attribute before substituting it, so a
         * message written to open with :attribute renders a sentence starting
         * in lower case. Every such message opens with a fixed word instead.
         */
        $this->assertMatchesRegularExpression(
            '/^\p{Lu}/u',
            $message,
            "{$key} starts in lower case: {$message}",
        );
    }

    public function test_the_locale_has_no_english_fallback_to_hide_behind(): void
    {
        /*
         * This is the condition that turned a missing file into visible
         * breakage. If somebody sets the fallback to `en` later, untranslated
         * keys start rendering in English instead — quieter, and still wrong
         * for an Indonesian-language product.
         */
        $this->assertSame('id', config('app.locale'));
        $this->assertSame('id', config('app.fallback_locale'));
    }

    public function test_a_duplicate_email_reads_as_indonesian_on_the_screen(): void
    {
        /*
         * The end-to-end version of the same property, on the form where it was
         * found. Asserting the key resolves is not the same as asserting the
         * screen shows the resolved text.
         */
        User::factory()->owner()->create(['email' => 'ada@example.test']);
        $owner = User::factory()->owner()->create();

        $component = Livewire::actingAs($owner)
            ->test(CreateStaff::class)
            ->fillForm([
                'name' => 'Kembar',
                'email' => 'ada@example.test',
                'role' => Role::Sales->value,
                'password' => 'sandi-awal-panjang',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);

        $errors = $component->errors()->get('data.email');

        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsString('validation.', $errors[0]);
        $this->assertStringContainsString('sudah dipakai', $errors[0]);
    }
}
