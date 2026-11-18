<?php

namespace App\Services\Validation;

interface Rule
{
    /** @return list<RuleResult> one or more results (pass, warn or fail) */
    public function check(RuleContext $ctx): array;
}
