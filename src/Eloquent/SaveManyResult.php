<?php

namespace Quatrebarbes\SnowDriver\Eloquent;

/**
 * EX-123 : détail des succès et échecs d'une opération groupée (saveMany).
 * Aucune garantie d'atomicité : les enregistrements de $successes ont déjà
 * été écrits côté ServiceNow même si d'autres enregistrements du même lot
 * sont en échec.
 */
final class SaveManyResult
{
    /**
     * @param  array<int, ServiceNowModel>  $successes
     * @param  array<int, SaveManyFailure>  $failures
     */
    public function __construct(
        public readonly array $successes,
        public readonly array $failures,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
