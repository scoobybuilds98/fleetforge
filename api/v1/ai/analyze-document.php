<?php
declare(strict_types=1);

/**
 * api/v1/ai/analyze-document.php
 *
 * AI document analysis — extracts and analyzes content from
 * uploaded documents (PDF, images) using Claude's vision capabilities.
 *
 * POST /api/v1/ai/analyze-document
 *   Content-Type: multipart/form-data
 *   Fields: file (uploaded file), prompt? (optional analysis instruction)
 *   → { analysis: "...", tokens_used: N }
 *
 * Supported formats: PDF, PNG, JPG, JPEG, GIF, WEBP
 * Max file size: 10MB
 *
 * Use cases:
 *   - Extract text from scanned inspection reports
 *   - Analyze compliance certificates
 *   - Read and summarize lease contracts
 *   - Parse invoice/receipt images
 *
 * Permission: ai:create
 *
 * @depends lib/AI/ClaudeClient.php
 * @session S027
 */

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

if (!can('ai', 'create')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

$userId = (int) ($_SESSION['ff_user']['id'] ?? 0);

// ── Validate file upload ───────────────────────────────────
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'File upload is required']);
    exit;
}

$file     = $_FILES['file'];
$maxSize  = 10 * 1024 * 1024; // 10MB
$prompt   = trim($_POST['prompt'] ?? '');

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'File too large. Maximum size is 10MB.']);
    exit;
}

// WHY: Only allow image types that Anthropic Vision API supports, plus PDF
$mimeType = mime_content_type($file['tmp_name']);
$allowedTypes = [
    'image/png'  => 'image/png',
    'image/jpeg' => 'image/jpeg',
    'image/gif'  => 'image/gif',
    'image/webp' => 'image/webp',
    'application/pdf' => 'application/pdf',
];

if (!isset($allowedTypes[$mimeType])) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Unsupported file type. Allowed: PDF, PNG, JPG, GIF, WEBP.']);
    exit;
}

// ── Initialize AI ──────────────────────────────────────────
$ai = new \FleetForge\AI\ClaudeClient();
if (!$ai->isEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => true, 'message' => 'AI features are not enabled.']);
    exit;
}

if (!\FleetForge\AI\TokenTracker::canSpend($userId)) {
    http_response_code(429);
    echo json_encode(['error' => true, 'message' => 'Daily AI token limit reached.']);
    exit;
}

// ── Build message with document ────────────────────────────
$fileData = file_get_contents($file['tmp_name']);
if ($fileData === false) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'Failed to read uploaded file.']);
    exit;
}

$base64 = base64_encode($fileData);

// WHY: Anthropic Vision API expects image data inline in the content blocks
if (str_starts_with($mimeType, 'image/')) {
    $contentBlocks = [
        [
            'type' => 'image',
            'source' => [
                'type'         => 'base64',
                'media_type'   => $mimeType,
                'data'         => $base64,
            ],
        ],
        [
            'type' => 'text',
            'text' => $prompt !== ''
                ? $prompt
                : 'Analyze this document image. Extract all text content, identify the document type, and summarize the key information. If this is a compliance certificate, inspection report, or lease document, highlight important dates, amounts, and conditions.',
        ],
    ];
} else {
    // WHY: PDF support via Anthropic's document type
    $contentBlocks = [
        [
            'type' => 'document',
            'source' => [
                'type'         => 'base64',
                'media_type'   => 'application/pdf',
                'data'         => $base64,
            ],
        ],
        [
            'type' => 'text',
            'text' => $prompt !== ''
                ? $prompt
                : 'Analyze this PDF document. Extract key information, identify the document type, and provide a structured summary. Highlight important dates, amounts, parties involved, and any conditions or requirements.',
        ],
    ];
}

$messages = [
    ['role' => 'user', 'content' => $contentBlocks],
];

$systemPrompt = <<<'PROMPT'
You are FleetForge AI Document Analyzer, specializing in analyzing documents for a trailer and equipment leasing company.

When analyzing documents:
- Extract all readable text content
- Identify the document type (inspection report, compliance certificate, lease agreement, invoice, receipt, etc.)
- Highlight key information: dates, amounts, parties, terms, conditions
- Flag any concerns or items requiring attention
- If the document is a compliance certificate, note the expiry date prominently
- Format monetary values with $ and two decimal places
- Use structured formatting (bullet points, sections) for clarity
- Keep the analysis concise but thorough
PROMPT;

$response = $ai->sendMessage(
    messages:     $messages,
    systemPrompt: $systemPrompt,
    tools:        [],
    maxTokens:    4096,
    userId:       $userId,
    queryType:    'document_analysis'
);

if ($response === null) {
    http_response_code(502);
    echo json_encode(['error' => true, 'message' => 'AI service unavailable.']);
    exit;
}

$analysis = \FleetForge\AI\ClaudeClient::extractTextContent($response);
$tokensUsed = ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

echo json_encode([
    'analysis'    => $analysis,
    'file_name'   => $file['name'],
    'file_type'   => $mimeType,
    'tokens_used' => $tokensUsed,
], JSON_UNESCAPED_UNICODE);
