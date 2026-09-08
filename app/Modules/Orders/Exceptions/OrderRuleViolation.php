<?php

namespace App\Modules\Orders\Exceptions;

use DomainException;

/**
 * A business rule refused the requested change (financial hold on dispatch, stage lock on cancel / edit, …).
 * Controllers turn it into a flash error; consumers treat it as "leave the order where it is", never as a retry.
 */
final class OrderRuleViolation extends DomainException {}
