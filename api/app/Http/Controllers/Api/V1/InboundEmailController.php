<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\EmailIngestor;
use Illuminate\Http\Request;

/**
 * Provider-neutral inbound email webhook (Postmark/Mailgun/SES can be adapted
 * to this shape by a tiny relay). Auth: X-ExtractIQ-Signature: sha256=HMAC(raw body).
 */
class InboundEmailController extends Controller
{
    public function __invoke(Request $request, EmailIngestor $ingestor)
    {
        $secret = (string) config('extractiq.inbound_email.webhook_secret');
        $given = (string) $request->header('X-ExtractIQ-Signature');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $given)) {
            return response()->json(['errors' => [['status' => '401', 'code' => 'bad_signature', 'title' => 'Invalid signature']]], 401);
        }

        $data = $request->validate([
            'from' => 'nullable|string|max:255',
            'to' => 'required',
            'subject' => 'nullable|string|max:500',
            'message_id' => 'nullable|string|max:255',
            'attachments' => 'array|max:20',
            'attachments.*.filename' => 'required|string|max:255',
            'attachments.*.content_type' => 'required|string|max:100',
            'attachments.*.content' => 'required|string', // base64
        ]);
        foreach ($data['attachments'] ?? [] as $i => $att) {
            $bytes = base64_decode($att['content'], true);
            if ($bytes === false) {
                return response()->json(['errors' => [['status' => '422', 'code' => 'invalid_base64', 'title' => 'Invalid attachment', 'source' => ['pointer' => "/attachments/$i/content"]]]], 422);
            }
            $data['attachments'][$i]['content'] = $bytes;
        }

        $result = $ingestor->ingest($data);
        if (! $result['company']) {
            return response()->json(['errors' => [['status' => '404', 'code' => 'unknown_recipient', 'title' => 'No tenant for this recipient address']]], 404);
        }

        return response()->json(['meta' => [
            'created' => $result['created'], 'duplicates' => $result['duplicates'], 'ignored' => $result['ignored'],
            'document_ids' => array_map('strval', $result['documents']),
        ]], 202);
    }
}
