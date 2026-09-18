<?php
declare(strict_types=1);

namespace CTF\Server\Models;

/**
 * Group entity — a class/team managed by one teacher, joined by students via join_code.
 *
 * @property-read int    $id
 * @property-read string $uuid
 * @property-read int    $teacher_id
 * @property-read string $name
 * @property-read ?string $description
 * @property-read string $join_code
 * @property-read ?int   $max_members
 * @property-read string $status
 * @property-read string $created_at
 * @property-read string $updated_at
 */
final class Group
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /** @var array<string,mixed> */
    private array $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->row;
    }

    public function __get(string $name): mixed
    {
        return $this->row[$name] ?? null;
    }

    public function isActive(): bool
    {
        return ($this->row['status'] ?? null) === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return ($this->row['status'] ?? null) === self::STATUS_ARCHIVED;
    }
}
