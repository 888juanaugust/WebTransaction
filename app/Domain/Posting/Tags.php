<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use Illuminate\Database\Eloquent\Model;

/**
 * The department and project a journal line is booked to. A line takes its
 * own, else its document's (the header's are the default); reports filter
 * by them when the Departments or Projects module is on.
 */
final class Tags
{
    public function __construct(public readonly ?int $departmentId = null, public readonly ?int $projectId = null) {}

    public static function none(): self
    {
        return new self;
    }

    /** The first department and the first project found on the models given, in order: Tags::of($line, $document). */
    public static function of(?Model ...$sources): self
    {
        $department = null;
        $project = null;
        foreach ($sources as $source) {
            if ($source === null) {
                continue;
            }
            $department ??= $source->getAttribute('department_id') !== null ? (int) $source->getAttribute('department_id') : null;
            $project ??= $source->getAttribute('project_id') !== null ? (int) $source->getAttribute('project_id') : null;
        }

        return new self($department, $project);
    }

    /** These tags, with the fallback's where these have none. */
    public function orElse(?self $fallback): self
    {
        return $fallback === null ? $this : new self($this->departmentId ?? $fallback->departmentId, $this->projectId ?? $fallback->projectId);
    }

    /** @return array{department_id: ?int, project_id: ?int} */
    public function toArray(): array
    {
        return ['department_id' => $this->departmentId, 'project_id' => $this->projectId];
    }
}
