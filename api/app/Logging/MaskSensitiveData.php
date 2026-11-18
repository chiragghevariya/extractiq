<?php

namespace App\Logging;

use App\Support\Pii;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor (attached to every channel via `tap` in config/logging.php): bank details in
 * a log message or its context are masked before the record is written anywhere.
 */
class MaskSensitiveData implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: Pii::mask($record->message), context: Pii::maskArray($record->context), extra: Pii::maskArray($record->extra));
    }

    /** `tap` entry point. */
    public static function tap(IlluminateLogger $logger): void
    {
        $monolog = $logger->getLogger();
        if (method_exists($monolog, 'pushProcessor')) {
            $monolog->pushProcessor(new self);
        }
    }
}
