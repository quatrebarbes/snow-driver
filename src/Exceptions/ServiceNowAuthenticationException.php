<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

/**
 * EX-120 : une erreur d'authentification (401/403) est distinguée des
 * autres erreurs API pour permettre une gestion différenciée côté appelant.
 */
class ServiceNowAuthenticationException extends ServiceNowApiException
{
}
