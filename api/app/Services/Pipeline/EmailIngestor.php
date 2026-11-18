<?php

namespace App\Services\Pipeline;

use App\Models\Company;

/** Routes an inbound email to its tenant by recipient address and ingests each supported attachment. */
class EmailIngestor
{
    private const MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/tiff'];

    public function __construct(private readonly DocumentIngestor $ingestor) {}

    /**
     * @param  array<string, mixed>  $email  {from?, to: string|list<string>, subject?, message_id?, attachments?: list<{filename?, content_type?, content}>}; attachment content is raw bytes
     * @return array{company: ?Company, created: int, duplicates: int, ignored: int, documents: list<int>}
     */
    public function ingest(array $email): array
    {
        $company = $this->resolveCompany((array) $email['to']);
        $out = ['company' => $company, 'created' => 0, 'duplicates' => 0, 'ignored' => 0, 'documents' => []];
        if (! $company) {
            $out['ignored'] = count($email['attachments'] ?? []);

            return $out;
        }

        foreach ($email['attachments'] ?? [] as $att) {
            $mime = strtolower($att['content_type'] ?? '');
            if (! in_array($mime, self::MIMES, true) && ! str_ends_with(strtolower($att['filename'] ?? ''), '.pdf')) {
                $out['ignored']++;

                continue;
            }
            [$doc, $created] = $this->ingestor->ingest($company, $att['content'], $att['filename'] ?: 'attachment.pdf', $mime ?: 'application/pdf', [
                'source' => 'email',
                'email_from' => $email['from'] ?? null,
                'email_subject' => $email['subject'] ?? null,
                'email_message_id' => $email['message_id'] ?? null,
            ]);
            $created ? $out['created']++ : $out['duplicates']++;
            $out['documents'][] = $doc->id;
        }

        return $out;
    }

    private function resolveCompany(array $recipients): ?Company
    {
        foreach ($recipients as $to) {
            if (preg_match('/<([^>]+)>/', $to, $m)) {
                $to = $m[1];
            }
            if ($c = Company::where('inbound_email', strtolower(trim($to)))->first()) {
                return $c;
            }
        }

        return null;
    }
}
