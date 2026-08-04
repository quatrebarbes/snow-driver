<?php

namespace Quatrebarbes\SnowDriver\Eloquent;

use RuntimeException;

/**
 * EX-123 : échec individuel d'un enregistrement au sein d'une opération
 * groupée (saveMany), associé au modèle concerné et à l'exception levée.
 */
final class SaveManyFailure
{
    public function __construct(
        public readonly ServiceNowModel $model,
        public readonly RuntimeException $exception,
    ) {
    }
}
