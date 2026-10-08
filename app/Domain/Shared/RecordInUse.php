<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Access\MenuRegistry;
use App\Domain\Audit\HasAuditReference;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A record something else still points at cannot be deleted. The database
 * refuses (every reference to a master or a document restricts deletes);
 * this turns that refusal into a sentence naming the screens that use the
 * record, with the advice to deactivate it when it can be deactivated.
 */
final class RecordInUse extends RuntimeException
{
    /** PostgreSQL's foreign_key_violation. */
    private const FOREIGN_KEY_VIOLATION = '23503';

    /** Line and charge tables are named after their document. */
    private const CHILD_SUFFIXES = ['_lines', '_charges', '_expenditures', '_down_payments'];

    /** @param  list<string>  $places */
    public function __construct(public readonly Model $record, public readonly array $places)
    {
        $sentence = __(':record is used on :places, so it cannot be deleted.', ['record' => self::nameOf($record), 'places' => implode(', ', $places)]);
        if (array_key_exists('is_active', $record->getAttributes())) {
            $sentence .= ' '.__('Deactivate it instead: it stays on what already uses it and leaves the pick lists.');
        }
        parent::__construct($sentence);
    }

    /**
     * Runs the delete in a transaction (a savepoint inside another), so a refusal leaves nothing half done.
     *
     * @template T
     *
     * @param  Closure(): T  $delete
     * @return T
     */
    public static function guard(Model $record, Closure $delete): mixed
    {
        try {
            return DB::transaction(fn () => $delete());
        } catch (QueryException $e) {
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) !== self::FOREIGN_KEY_VIOLATION) {
                throw $e;
            }
            $places = self::placesOf($record);

            throw new self($record, $places !== [] ? $places : [self::label(self::tableIn($e) ?? $record->getTable(), $record->getTable())]);
        }
    }

    /** @return list<string> the screens whose records point at this one, through a reference that refuses deletes */
    private static function placesOf(Model $record): array
    {
        $references = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.confrelid = ?::regclass AND c.confdeltype IN ('r', 'a')
            ORDER BY 1, 2
            SQL, [$record->getTable()]);

        $places = [];
        foreach ($references as $reference) {
            if (DB::table($reference->table_name)->where($reference->column_name, $record->getKey())->exists()) {
                $places[] = self::label($reference->table_name, $record->getTable());
            }
        }

        return array_values(array_unique($places));
    }

    /** The table the database named in its refusal ("... still referenced from table "x""). */
    private static function tableIn(QueryException $e): ?string
    {
        return preg_match('/referenced from table "([^"]+)"/', $e->getMessage(), $m) === 1 ? $m[1] : null;
    }

    private static function label(string $table, string $ownTable): string
    {
        $registry = app(MenuRegistry::class);
        $key = $registry->menuKeyForTable($table);
        foreach (self::CHILD_SUFFIXES as $suffix) {
            if ($key === null && str_ends_with($table, $suffix)) {
                $key = $registry->menuKeyForTable(Str::plural(Str::beforeLast($table, $suffix)));
            }
        }
        $label = match (true) {
            $key !== null => $key->label(),
            in_array($table, ['journal_lines', 'journal_entries', 'postings'], true) => __('the general ledger'),
            $table === 'stock_movements' => __('the stock ledger'),
            default => Str::headline($table),
        };

        return $table === $ownTable ? __(':place (under it)', ['place' => $label]) : $label;
    }

    private static function nameOf(Model $record): string
    {
        if ($record instanceof HasAuditReference) {
            return $record->auditReference();
        }
        foreach (['name', 'number', 'code', 'description'] as $attribute) {
            if (filled($record->getAttribute($attribute))) {
                return (string) $record->getAttribute($attribute);
            }
        }

        return __('This record');
    }
}
