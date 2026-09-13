<?php

namespace Waar\MicroCombat\Workshop;

final class ProfileValidationException extends \InvalidArgumentException
{
    /** @param list<array{code:string,path:string,message:string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['message'] ?? 'Profil invalide.');
    }
}
