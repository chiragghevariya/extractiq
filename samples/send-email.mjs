#!/usr/bin/env node
/**
 * Send sample documents to the demo tenant's inbound address through Mailpit's
 * HTTP send API (no SMTP client needed). Then either wait for the scheduler or
 * run `php artisan extractiq:poll-mailpit` in /api.
 *
 *   node send-email.mjs pdf/scenarios/01-po-1001-kestrel.pdf [more.pdf ...]
 */
import fs from 'node:fs';
import path from 'node:path';

const files = process.argv.slice(2);
if (!files.length) {
  console.error('usage: node send-email.mjs <file.pdf> [...]');
  process.exit(1);
}
const MAILPIT = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';
const TO = process.env.INBOUND ?? 'ap-demo@inbound.extractiq.test';

const res = await fetch(`${MAILPIT}/api/v1/send`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    From: { Email: 'billing@kestrel-parts.example', Name: 'Kestrel Billing' },
    To: [{ Email: TO, Name: 'Accounts Payable' }],
    Subject: `Documents: ${files.map((f) => path.basename(f)).join(', ')}`,
    Text: 'Please find attached. (ExtractIQ sample — fictional data)',
    Attachments: files.map((f) => ({ Filename: path.basename(f), ContentType: 'application/pdf', Content: fs.readFileSync(f).toString('base64') })),
  }),
});
console.log(res.ok ? `Sent to ${TO} via Mailpit (${(await res.json()).ID})` : `Mailpit error ${res.status}: ${await res.text()}`);
