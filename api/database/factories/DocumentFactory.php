<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => DocumentType::Invoice,
            'source' => 'upload',
            'status' => DocumentStatus::Extracted,
            'original_filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'storage_disk' => 'local',
            'storage_path' => 'test/'.Str::uuid().'.pdf',
            'sha256' => hash('sha256', Str::random(40)),
            'vendor_name' => 'Acme Test Supplies',
            'document_number' => 'INV-'.$this->faker->unique()->numberBetween(1000, 99999),
            'po_number' => null,
            'document_date' => now()->subDays(3)->toDateString(),
            'due_date' => now()->addDays(27)->toDateString(),
            'currency' => 'USD',
            'subtotal_minor' => 10000,
            'tax_minor' => 800,
            'total_minor' => 10800,
            'confidence' => 0.95,
            'received_at' => now(),
        ];
    }

    public function status(DocumentStatus $s): static
    {
        return $this->state(['status' => $s]);
    }

    public function type(DocumentType $t): static
    {
        return $this->state(['type' => $t]);
    }

    /** @param list<array{0: ?string, 1: float, 2: ?int}> $lines [sku, qty, unit_price_minor] */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (Document $doc) use ($lines) {
            foreach ($lines as $i => [$sku, $qty, $price]) {
                DocumentLine::withoutGlobalScopes()->create([
                    'company_id' => $doc->company_id, 'document_id' => $doc->id, 'line_no' => $i + 1,
                    'sku' => $sku, 'description' => "Item $sku", 'quantity' => $qty,
                    'unit_price_minor' => $price, 'amount_minor' => $price === null ? null : (int) round($qty * $price),
                ]);
            }
        });
    }
}
