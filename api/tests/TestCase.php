<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'extractiq.ocr.default' => 'pdftext',
            'extractiq.llm.default' => 'heuristic',
            'extractiq.erp.default' => 'mock',
            'extractiq.pipeline.auto_approve_validated' => false,
            'extractiq.documents_disk' => 'local',
            'extractiq.inbound_email.webhook_secret' => 'test-secret',
        ]);
    }
}
