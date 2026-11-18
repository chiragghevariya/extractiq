<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class MaskPiiTap
{
    public function __invoke(Logger $logger): void
    {
        MaskSensitiveData::tap($logger);
    }
}
