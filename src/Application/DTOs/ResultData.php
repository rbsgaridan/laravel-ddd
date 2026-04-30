<?php

namespace Incoder\DDD\Application\DTOs;

class ResultData extends DTOBase
{
    public function __construct(
        public object $entity,
        public object $dto
    ) {}
}
