<?php

declare(strict_types=1);

namespace App\Client\Seeders;

use App\Client\Portal\PortalActor;
use App\Domain\Access\Hak;
use App\Domain\Access\MenuKey;
use App\Models\Company\Branch;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Portal system user and its group: the staff account in whose name
 * the portal writes orders and renders documents. An operator with a
 * random password nobody holds, member of the Portal group alone, in every
 * branch. Seeding again touches neither the password nor a reshaped group.
 */
class PortalUserSeeder extends Seeder
{
    public const GROUP = 'Portal';

    public function run(): void
    {
        $group = AccessGroup::query()->firstOrCreate(['name' => self::GROUP], ['restriction_type' => 'preferences']);
        if (! $group->rights()->exists()) {
            $group->syncRights([
                MenuKey::SalesOrders->value => [Hak::View->value, Hak::Create->value, Hak::Print->value],
                MenuKey::DeliveryOrders->value => [Hak::View->value, Hak::Print->value],
                MenuKey::SalesInvoices->value => [Hak::View->value, Hak::Print->value],
                MenuKey::Customers->value => [Hak::View->value],
                MenuKey::ItemsAndServices->value => [Hak::View->value],
            ]);
            $group->syncSpecialRights([]);
        }

        $email = PortalActor::email();
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $user = new User(['name' => 'Portal', 'email' => $email, 'access_type' => 'operator', 'is_active' => true]);
            $user->forceFill(['password' => Hash::make(Str::password(64))])->save();
        }
        $group->users()->syncWithoutDetaching([$user->id]);
        $user->branches()->syncWithoutDetaching(Branch::query()->pluck('id')->all());
    }
}
