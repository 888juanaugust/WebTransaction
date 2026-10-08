<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use RuntimeException;

final class AlreadyInstalled extends RuntimeException {}
