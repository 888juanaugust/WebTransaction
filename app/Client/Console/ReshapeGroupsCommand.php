<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Access\CentralGroups;
use App\Client\Seeders\CentralGroupSeeder;
use App\Domain\Audit\Auditor;
use Illuminate\Console\Command;

/**
 * Re-applies Central's rights matrix to the groups of an installed company.
 * The seeder leaves a shaped group alone; this is the owner's way to take
 * a new matrix after an update. Every group's change is audited.
 */
class ReshapeGroupsCommand extends Command
{
    protected $signature = 'central:reshape-groups {--dry-run : Show what would change and change nothing}';

    protected $description = 'Re-apply the rights of Central\'s roles to their groups';

    public function handle(CentralGroupSeeder $seeder): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry) {
            $seeder->mergeLegacyInventory();
        }
        foreach ($seeder->matrix() as $role => [$rights, $special]) {
            $group = CentralGroups::claim($role);
            $before = ['rights' => $group->load('rights')->rightsMatrix(), 'special' => $group->specialRights()->pluck('right')->sort()->values()->all()];
            $after = ['rights' => $rights, 'special' => collect($special)->map(fn ($r) => $r->value)->sort()->values()->all()];
            $changed = $this->differs($before, $after);
            $this->line(sprintf('%-14s %-20s %s', $role, $group->name, $changed ? __('reshaped') : __('unchanged')));
            if (! $changed || $dry) {
                continue;
            }
            $seeder->apply($group, $rights, $special);
            Auditor::log('groups_reshaped', $group, $group->name, ['before' => $before, 'after' => $after]);
        }

        return self::SUCCESS;
    }

    /** @param  array{rights: array<string, list<string>>, special: list<string>}  $a */
    private function differs(array $a, array $b): bool
    {
        $norm = function (array $m): array {
            $out = [];
            foreach ($m['rights'] as $key => $rights) {
                sort($rights);
                if ($rights !== []) {
                    $out[$key] = $rights;
                }
            }
            ksort($out);

            return ['rights' => $out, 'special' => $m['special']];
        };

        return $norm($a) !== $norm($b);
    }
}
