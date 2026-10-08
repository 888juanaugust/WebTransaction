<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Access\ScreenKey;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The modules this installation has (config/modules.php plus the client's
 * own) and which of them are switched on. A screen is reachable when its
 * module is on; the morph map is the union of every module, on or off, so
 * ledgers and logs that already hold a module's rows still read.
 */
final class ModuleRegistry
{
    /** @var list<class-string<Module>> */
    private array $classes;

    /** @var array<string, class-string<Module>>|null menu key value → module class */
    private ?array $owners = null;

    /** @param list<class-string<Module>> $classes */
    public function __construct(array $classes, private readonly Preferensi $prefs)
    {
        $this->classes = array_values(array_unique($classes));
        foreach ($this->classes as $class) {
            if (! is_subclass_of($class, Module::class)) {
                throw new InvalidArgumentException("{$class} is not a module.");
            }
        }
    }

    /** @return list<class-string<Module>> */
    public function all(): array
    {
        return $this->classes;
    }

    /** @return list<class-string<Module>> */
    public function enabled(): array
    {
        return array_values(array_filter($this->classes, fn (string $class) => $this->isEnabledClass($class)));
    }

    /** @return list<class-string<Module>> the modules a Features preference can switch */
    public function switchable(): array
    {
        return array_values(array_filter($this->classes, fn (string $class) => $class::feature() !== null));
    }

    public function find(string $key): ?string
    {
        foreach ($this->classes as $class) {
            if ($class::key() === $key) {
                return $class;
            }
        }

        return null;
    }

    public function isEnabled(string $key): bool
    {
        $class = $this->find($key);

        return $class !== null && $this->isEnabledClass($class);
    }

    public function ownerOf(ScreenKey $key): ?string
    {
        return $this->owners()[$key->value] ?? null;
    }

    /** A screen nobody owns stays reachable; an owned one follows its module's (or its own) switch. */
    public function menuKeyEnabled(ScreenKey $key): bool
    {
        $owner = $this->ownerOf($key);
        if ($owner === null) {
            return true;
        }
        $feature = $owner::featureForKey($key);

        return $feature === null || $this->prefs->isOn($feature);
    }

    /** @return array<string, class-string<Model>> */
    public function morphMap(): array
    {
        $map = [];
        foreach ($this->classes as $class) {
            $map += $class::morphMap();
        }

        return $map;
    }

    /** Switch every module on (tests, and an install that asked for everything). */
    public function enableAll(?User $actor = null): void
    {
        $values = [];
        foreach ($this->classes as $class) {
            $feature = $class::feature();
            if ($feature !== null) {
                $values[$feature->value] = true;
            }
        }
        if ($values !== []) {
            $this->prefs->setMany($values, $actor);
        }
    }

    /** @param array<string, bool> $features feature preference key → on/off */
    public function setFeatures(array $features, ?User $actor = null): void
    {
        $values = [];
        foreach ($features as $key => $on) {
            $pref = PreferensiKey::tryFrom($key);
            if ($pref !== null) {
                $values[$pref->value] = (bool) $on;
            }
        }
        if ($values !== []) {
            $this->prefs->setMany($values, $actor);
        }
    }

    private function isEnabledClass(string $class): bool
    {
        $feature = $class::feature();

        return $feature === null || $this->prefs->isOn($feature);
    }

    /** @return array<string, class-string<Module>> */
    private function owners(): array
    {
        if ($this->owners === null) {
            $this->owners = [];
            foreach ($this->classes as $class) {
                foreach ($class::menuKeys() as $key) {
                    $this->owners[$key->value] = $class;
                }
            }
        }

        return $this->owners;
    }
}
