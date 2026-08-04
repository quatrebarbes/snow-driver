<?php

namespace Quatrebarbes\SnowDriver\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * EX-119 : toute réponse HTTP 4xx/5xx de l'API ServiceNow est traduite en
 * exception dédiée portant le code et le message d'erreur ServiceNow.
 */
class ServiceNowApiException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly ?string $serviceNowMessage,
        private readonly ?string $serviceNowDetail,
    ) {
        parent::__construct(sprintf(
            'Erreur API ServiceNow [%d] : %s',
            $statusCode,
            $serviceNowMessage ?? 'erreur inconnue'
        ));
    }

    public static function fromResponse(Response $response): static
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        return new static(
            $response->status(),
            $error['message'] ?? null,
            $error['detail'] ?? null,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function serviceNowMessage(): ?string
    {
        return $this->serviceNowMessage;
    }

    public function serviceNowDetail(): ?string
    {
        return $this->serviceNowDetail;
    }
}
