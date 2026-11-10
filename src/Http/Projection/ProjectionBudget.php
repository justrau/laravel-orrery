<?php

namespace Justrau\Orrery\Http\Projection;

/**
 * The budget of one projection (N-2): one instance for one
 * field (the payload and its command share one), nested values included.
 *
 * - input: at most INPUT_BYTES bytes per parsed value, checked before the
 *   parse starts;
 * - depth: a value deeper than DEPTH fails; a nested serialized value starts
 *   at its string's depth + 1, so the depth also bounds the nesting;
 * - nodes: at most NODES parsed values; a declared count above the nodes
 *   that remain stops the parse before anything is allocated;
 * - work: one counter of the bytes that every parse of the projection may
 *   scan, outer and nested. Each parse charges the length of its range when
 *   it starts, so nested values never cost more than linear work.
 *
 * A limit throws ProjectionLimit, whose message names only the limit.
 */
final class ProjectionBudget
{
    public const INPUT_BYTES = 1_048_576;

    public const DEPTH = 32;

    public const NODES = 20_000;

    public const WORK_BYTES = 2_097_152;

    private int $nodes = 0;

    private int $work = 0;

    public function __construct(
        public readonly int $inputBytes = self::INPUT_BYTES,
        public readonly int $depth = self::DEPTH,
        public readonly int $maxNodes = self::NODES,
        public readonly int $workBytes = self::WORK_BYTES,
    ) {}

    /**
     * @throws ProjectionLimit
     */
    public function input(int $bytes): void
    {
        if ($bytes > $this->inputBytes) {
            throw new ProjectionLimit(ProjectionLimit::INPUT);
        }
    }

    /**
     * Charges the bytes of one parse range.
     *
     * @throws ProjectionLimit
     */
    public function charge(int $bytes): void
    {
        $this->work += $bytes;

        if ($this->work > $this->workBytes) {
            throw new ProjectionLimit(ProjectionLimit::WORK);
        }
    }

    /**
     * @throws ProjectionLimit
     */
    public function enter(int $depth): void
    {
        if ($depth > $this->depth) {
            throw new ProjectionLimit(ProjectionLimit::DEPTH);
        }
    }

    /**
     * Counts one parsed value.
     *
     * @throws ProjectionLimit
     */
    public function node(): void
    {
        if (++$this->nodes > $this->maxNodes) {
            throw new ProjectionLimit(ProjectionLimit::NODES);
        }
    }

    /**
     * Refuses a declared count that the remaining nodes cannot hold, before
     * any entry is read.
     *
     * @throws ProjectionLimit
     */
    public function declare(int $count): void
    {
        if ($count > $this->maxNodes - $this->nodes) {
            throw new ProjectionLimit(ProjectionLimit::NODES);
        }
    }

    public function nodes(): int
    {
        return $this->nodes;
    }

    public function work(): int
    {
        return $this->work;
    }
}
