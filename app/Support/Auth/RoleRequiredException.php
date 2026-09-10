<?php

namespace App\Support\Auth;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** 403 raised by a code-level role check; carries the roles that DO have access so errors/403 can name them. */
class RoleRequiredException extends HttpException
{
    /** @param  list<string>  $roles */
    public function __construct(public readonly array $roles, string $message = '')
    {
        parent::__construct(403, $message);
    }
}
