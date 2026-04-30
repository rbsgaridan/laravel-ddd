<?php

namespace Incoder\DDD\Support\Attributes;

use Attribute;

/**
 * Fillable Attribute for detecting the correct fillable property for the Entity
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class FillableAttribute
{
    /**
     * Implement your own logic here
     */
    public function __construct() {}
}
