<?php

return [
    'documents_disk' => env('DOCUMENTS_DISK', 'local'),
    // The web app (links in ERP bills and webhook payloads, OAuth return pages).
    'web_url' => env('FRONTEND_URL', 'http://localhost:3030'),
    'max_upload_kb' => (int) env('MAX_UPLOAD_KB', 20480),
    'allowed_mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff'],

    // Every extracted field must be at least this confident (and no rule may fail) to be "validated".
    'review_confidence_threshold' => (float) env('REVIEW_CONFIDENCE_THRESHOLD', 0.90),

    'review' => [
        // Target time from arrival to a review decision (drives the SLA timer in the review queue).
        'sla_hours' => (int) env('REVIEW_SLA_HOURS', 24),
        'sla_warning_hours' => (int) env('REVIEW_SLA_WARNING_HOURS', 4),
    ],

    'extraction' => [
        // Versioned schemas in resources/schemas/{type}.{version}.json
        'schema_versions' => ['invoice' => 'v1', 'purchase_order' => 'v1', 'goods_receipt' => 'v1'],
        // USD per 1M tokens, for the cost stored on each document (override as prices change).
        'prices' => [
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
            'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
            'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            'regex-v2' => ['input' => 0, 'output' => 0],
        ],
        // Keyword rules decide the type when they can; otherwise the LLM classifies below/above this.
        'llm_classify_min_confidence' => (float) env('LLM_CLASSIFY_MIN_CONFIDENCE', 0.6),
    ],

    'pages' => [
        'dpi' => (int) env('PAGE_IMAGE_DPI', 300),
        'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    ],

    'pipeline' => [
        'queue' => env('PIPELINE_QUEUE', 'pipeline'),
        'tries' => 3,
        'backoff' => [10, 60, 300],
        // Push every validated document straight to approval (no human click).
        'auto_approve_validated' => (bool) env('AUTO_APPROVE_VALIDATED', false),
    ],

    // Defaults for every company; each company can override them in Settings (companies.settings.matching).
    'matching' => [
        'price_tolerance_pct' => (float) env('MATCH_PRICE_TOLERANCE_PCT', 2.0),   // unit price may differ by this %…
        'price_tolerance_abs' => (float) env('MATCH_PRICE_TOLERANCE_ABS', 0.05),  // …or by this much (major units), whichever is larger
        'qty_tolerance_pct' => (float) env('MATCH_QTY_TOLERANCE_PCT', 0.0),       // invoiced qty may exceed ordered / received by this %
        'total_tolerance_abs' => (float) env('MATCH_TOTAL_TOLERANCE_ABS', 5.00),  // invoice vs expected value (qty × PO price)
        'mode' => env('MATCH_MODE', 'auto'),                                     // auto (3-way once goods are received) | two_way | three_way
        'accept_role' => env('MATCH_ACCEPT_ROLE', 'admin'),                      // who may accept a variance: admin | reviewer
    ],

    // Analytics: OCR cost per page, USD (list prices; local providers cost nothing). Textract runs
    // AnalyzeExpense for invoices and AnalyzeDocument (tables + forms) for everything else.
    'ocr_cost_per_page' => [
        'textract_expense' => (float) env('OCR_COST_TEXTRACT_EXPENSE', 0.01),
        'textract_document' => (float) env('OCR_COST_TEXTRACT_DOCUMENT', 0.065),
        'tesseract' => 0.0,
        'pdftext' => 0.0,
    ],

    'ocr' => [
        'default' => env('OCR_DRIVER', 'pdftext'),
        'drivers' => [
            // Digital PDFs via poppler's text layer (exact word boxes); scans/images fall back to Tesseract.
            'pdftext' => ['binary' => env('PDFTOTEXT_BINARY', 'pdftotext')],
            'tesseract' => [
                'binary' => env('TESSERACT_BINARY', 'tesseract'),
                'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
                'lang' => env('TESSERACT_LANG', 'eng'),
                'psm' => (int) env('TESSERACT_PSM', 3),
                'dpi' => 300,
            ],
            'textract' => [
                'region' => env('TEXTRACT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
                'poll_interval_seconds' => (float) env('TEXTRACT_POLL_SECONDS', 5),
                'max_wait_seconds' => (int) env('TEXTRACT_MAX_WAIT_SECONDS', 300),
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ],
    ],

    'llm' => [
        'default' => env('LLM_DRIVER', 'heuristic'),
        'drivers' => [
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
                'effort' => env('ANTHROPIC_EFFORT', 'medium'),
                'max_tokens' => 16000,
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],
            'heuristic' => [],
        ],
    ],

    'erp' => [
        // The connector used until a company picks one on the ERP Sync page.
        'default' => env('ERP_DRIVER', 'mock'),
        // PushToErp: 5 attempts, then the push is dead-lettered (push_failed) with the ERP's message.
        'push' => [
            'tries' => (int) env('ERP_PUSH_TRIES', 5),
            'backoff' => array_map('intval', explode(',', (string) env('ERP_PUSH_BACKOFF', '30,120,600,1800'))),
        ],
        // The scheduled pull (vendors, POs, receipts, bill status) runs every 15 minutes.
        'sync_every_minutes' => 15,
        'drivers' => [
            'mock' => [
                // Fail every Nth push to demo retries and dead-lettering (0 = never). The Mock ERP screen can also switch an outage on.
                'fail_every' => (int) env('ERP_MOCK_FAIL_EVERY', 0),
            ],
            'odoo' => [
                'url' => env('ODOO_URL', 'http://localhost:8069'),
                'database' => env('ODOO_DB', 'odoo'),
                'username' => env('ODOO_USERNAME', 'admin'),
                'password' => env('ODOO_PASSWORD'),             // or an API key
                'expense_account_code' => env('ODOO_EXPENSE_ACCOUNT', '600000'),
            ],
            'quickbooks' => [
                // OAuth 2.0 app from developer.intuit.com; tokens are obtained by "Connect to QuickBooks" and stored encrypted.
                'client_id' => env('QBO_CLIENT_ID'),
                'client_secret' => env('QBO_CLIENT_SECRET'),
                'redirect_uri' => env('QBO_REDIRECT_URI', 'http://localhost:8030/api/v1/erp/quickbooks/callback'),
                'environment' => env('QBO_ENVIRONMENT', 'sandbox'),
                'base_url' => env('QBO_BASE_URL', 'https://sandbox-quickbooks.api.intuit.com'),
                'app_url' => env('QBO_APP_URL', 'https://app.sandbox.qbo.intuit.com'),
                'authorize_url' => 'https://appcenter.intuit.com/connect/oauth2',
                'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
                'revoke_url' => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
                'expense_account_id' => env('QBO_EXPENSE_ACCOUNT_ID', '7'),
            ],
            'webhook' => [
                'url' => env('ERP_WEBHOOK_URL'),
                'secret' => env('ERP_WEBHOOK_SECRET'),
            ],
        ],
    ],

    'inbound_email' => [
        'domain' => env('INBOUND_EMAIL_DOMAIN', 'inbound.extractiq.test'),
        // How mailboxes are read every minute: mailpit (local dev REST API) or imap (any IMAP server).
        'driver' => env('INBOUND_MAIL_DRIVER', 'mailpit'),
        'imap' => [
            'host' => env('IMAP_HOST'),
            'port' => (int) env('IMAP_PORT', 993),
            'encryption' => env('IMAP_ENCRYPTION', 'ssl'),
            'username' => env('IMAP_USERNAME'),
            'password' => env('IMAP_PASSWORD'),
            'folder' => env('IMAP_FOLDER', 'INBOX'),
        ],
        // Shared secret for POST /api/v1/inbound/email (HMAC-SHA256 of raw body).
        'webhook_secret' => env('INBOUND_EMAIL_SECRET'),
        'mailpit_url' => env('MAILPIT_API_URL', 'http://127.0.0.1:8025'),
    ],

    'security' => [
        // Lifetime of presigned S3 / signed links to originals and page images.
        'signed_url_ttl' => (int) env('SIGNED_URL_TTL', 300),
        // Requests per minute (per user, or per IP when signed out).
        'rate_limits' => [
            'api' => (int) env('RATE_LIMIT_API', 600),
            'uploads' => (int) env('RATE_LIMIT_UPLOADS', 30),
            'exports' => (int) env('RATE_LIMIT_EXPORTS', 10),
            'login' => (int) env('RATE_LIMIT_LOGIN', 10),
        ],
    ],

    // Public demo: a read-only login button, a banner, and a nightly reset of the demo tenant.
    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),
        'company' => env('DEMO_COMPANY', 'demo'),
        'email' => env('DEMO_USER_EMAIL', 'demo@extractiq.test'),
        'reset_at' => env('DEMO_RESET_AT', '03:30'),
    ],
];
