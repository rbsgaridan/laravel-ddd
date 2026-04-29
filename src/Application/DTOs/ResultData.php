<?php

namespace Incoder\DDD\Application\DTOs;

use Incoder\DDD\Application\DTOs\DTOBase;

class ResultData extends DTOBase
{
    public function __construct(
        public object $entity,
        public object $dto
    ) {}
}
