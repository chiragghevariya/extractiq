# Extraction fixtures

Three invoices used by `tests/Feature/Extraction/ExtractionPipelineTest.php`:

| Fixture | Source sample | What it exercises |
|---|---|---|
| `clean` | `03-inv-k-2041-kestrel-3way-match.pdf` (poppler OCR) | every rule passes, all fields ≥ 0.90 → validated |
| `math_error` | `12-inv-bl-7790-brightline-total-mismatch.pdf` | subtotal + tax ≠ total → `total_math` fails → needs_review |
| `low_confidence` | `scans/14-scan-ncs-9050.png` (Tesseract OCR) | the model is confident but some source words have low OCR confidence → field confidence capped → needs_review |

`ocr.json` is the normalised OCR (words/lines/key_values/tables per page) exactly as `RunOcr` stores it.
`llm_reply.json` is a model reply in the wrapped `invoice.v1` extraction schema (value/confidence/source per field),
served to the Anthropic and OpenAI drivers through mocked transports.
