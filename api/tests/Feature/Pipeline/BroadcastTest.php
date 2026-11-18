<?php

use App\Events\DocumentStatusChanged;
use App\Models\Vendor;
use Illuminate\Support\Facades\Event;

it('broadcasts every transition with the status at that moment', function () {
    Event::fake([DocumentStatusChanged::class]);
    $company = demoCompany();
    Vendor::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Kestrel Industrial Parts LLC']);

    ingestSample($company, '01-po-1001-kestrel.pdf');

    $seen = [];
    Event::assertDispatched(DocumentStatusChanged::class, function (DocumentStatusChanged $e) use (&$seen, $company) {
        $seen[] = "{$e->from}->{$e->to}";

        return $e->broadcastOn()[0]->name === "private-company.{$company->id}";
    });
    expect($seen)->toBe(['received->ocr_done', 'ocr_done->extracted', 'extracted->validated']);
});
