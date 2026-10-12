<?php
/**
 * LOI brochure · PDF generator.
 *
 * ?id=<brochure_id> — resolves placeholders against the brochure's
 * selected jobs, lays each page out at A4 size with its background
 * image + the absolutely-positioned labels, and either streams a PDF
 * via Dompdf (preferred) or hands the browser an A4-styled HTML page
 * ready for "Save as PDF" from the print dialog (fallback when Dompdf
 * is not installed under vendor/).
 *
 * Any LOI user can generate.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_brochure_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_brochure_bootstrap();
loi_jobs_bootstrap();

if (!user_can_access_module($viewerId, 'loi_jobs') && !is_admin($viewer)) {
    http_response_code(403); echo 'Access denied.'; exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Missing brochure id.'; exit; }

$st = db()->prepare('SELECT * FROM loi_brochure WHERE id = ? LIMIT 1');
$st->execute([$id]);
$brochure = $st->fetch();
if ($brochure === false) { http_response_code(404); echo 'Brochure not found.'; exit; }

$templateId = (int) ($brochure['template_id'] ?? 0);
$pages = $templateId > 0 ? loi_brochure_template_pages($templateId) : [];
$jobIds = loi_brochure_job_ids($id);
$placeholders = loi_brochure_placeholders((string) $brochure['title'], $jobIds);

// Resolve absolute file paths for background images so Dompdf can
// read them directly without a URL round-trip.
$toAbs = static function (?string $relPath): ?string {
    if ($relPath === null || $relPath === '') return null;
    $abs = __DIR__ . '/' . ltrim($relPath, '/');
    return is_file($abs) ? $abs : null;
};

// --- Build the HTML body ---------------------------------------------
// A4 at 96 DPI: 794×1123 CSS px. Each .brochure-page is sized exactly
// so Dompdf and the browser print engine both land it on one A4 sheet.
$pagesHtml = '';
if ($pages === []) {
    $pagesHtml .= '<div class="brochure-page"><div style="padding: 40px; font-family: sans-serif;">
        <h1>' . esc((string) $brochure['title']) . '</h1>
        <p style="color:#555;">This brochure has no template with pages yet.
        Pick a template on the brochure edit screen, or create one from Templates.</p>
    </div></div>';
}
foreach ($pages as $idx => $page) {
    $bg = $toAbs((string) ($page['background_image_path'] ?? ''));
    $labels = loi_brochure_page_labels($page);
    $pagesHtml .= '<div class="brochure-page" ' . ($idx > 0 ? 'style="page-break-before: always;"' : '') . '>';
    if ($bg !== null) {
        $pagesHtml .= '<img class="brochure-bg" src="file://' . esc($bg) . '" alt="">';
    }
    foreach ($labels as $l) {
        $x = (int) ($l['x'] ?? 0);
        $y = (int) ($l['y'] ?? 0);
        $w = isset($l['width']) && $l['width'] !== null && $l['width'] !== '' ? (int) $l['width'] : 0;
        $sz = (float) ($l['font_size'] ?? 14);
        $color = (string) ($l['color'] ?? '#000');
        $weight = (string) ($l['weight'] ?? 'normal');
        $align = (string) ($l['align'] ?? 'left');
        $text = loi_brochure_apply_placeholders((string) ($l['text'] ?? ''), $placeholders);
        $styles = 'position:absolute;'
            . 'left:' . $x . 'px;'
            . 'top:'  . $y . 'px;'
            . ($w > 0 ? 'width:' . $w . 'px;' : '')
            . 'font-size:' . $sz . 'pt;'
            . 'color:' . $color . ';'
            . 'font-weight:' . ($weight === 'bold' ? 'bold' : 'normal') . ';'
            . 'text-align:' . $align . ';'
            . 'line-height: 1.3;';
        // Text is treated as HTML-with-placeholders so {jobs_list} and
        // friends can emit <ol>/<ul> markup; esc() is applied inside the
        // placeholder resolver where appropriate.
        $pagesHtml .= '<div style="' . $styles . '">' . $text . '</div>';
    }
    $pagesHtml .= '</div>';
}

$html = '<!doctype html><html><head><meta charset="utf-8"><title>' . esc((string) $brochure['title']) . '</title>
<style>
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111; }
    .brochure-page { position: relative; width: 210mm; height: 297mm; overflow: hidden; }
    .brochure-bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; z-index: 0; }
    .brochure-page > div { z-index: 1; }
    @media screen {
        body { background: #e5e7eb; padding: 20px; }
        .brochure-page { background: #fff; margin: 0 auto 16px auto; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .print-bar { max-width: 210mm; margin: 0 auto 12px auto; background: #fff; padding: 8px 12px; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
        .print-bar button { padding: 6px 12px; background: #0d6efd; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    }
    @media print { .print-bar { display: none; } }
</style></head><body>';

$useDompdf = loi_brochure_dompdf_available();
if (!$useDompdf) {
    // Browser-print fallback — show a print button + the pages.
    $html .= '<div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button>'
          . ' &nbsp; <span style="color:#555; font-size: 12px;">Dompdf isn\'t installed on this server, so we\'re handing you an A4-styled HTML. Pick <strong>Save as PDF</strong> in the browser\'s print dialog, un-tick "Headers and footers" for a clean output.</span></div>';
}

$html .= $pagesHtml . '</body></html>';

if ($useDompdf) {
    // --- One-click PDF via Dompdf -----------------------------------
    try {
        $dompdf = new Dompdf\Dompdf([
            'isRemoteEnabled'          => true,  // allow file:// background images
            'isHtml5ParserEnabled'     => true,
            'defaultFont'              => 'DejaVu Sans',
            'chroot'                   => realpath(__DIR__),
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $brochure['title']) ?: ('brochure_' . $id);
        $dompdf->stream($filename . '.pdf', ['Attachment' => false]); // inline
        exit;
    } catch (Throwable $e) {
        // Fall through to HTML render if Dompdf blows up on something.
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><p>PDF render failed: ' . esc($e->getMessage()) . '. Showing HTML preview instead.</p>' . $html;
        exit;
    }
}

// Fallback render.
header('Content-Type: text/html; charset=utf-8');
echo $html;
