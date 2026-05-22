<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$requiredColumns = [
    'Job Fair ID' => 'job_fair_id',
    'Job Fair Date' => 'job_fair_date',
    'SL. No.' => 'sl_no',
    'DWMS ID' => 'dwms_id',
    'Name' => 'candidate_name',
    'Job ID' => 'job_id',
    'Job Role' => 'job_role',
    'Date of issuing offer letter' => 'date_of_issuing_offer_letter',
    'Offer Letter Generated (Yes/No)' => 'offer_letter_generated',
    'Offer Letter Given to Candidate (Yes/No)' => 'offer_letter_given_to_candidate',
    'Link to offer letter' => 'link_to_offer_letter',
    'Offer Letter Generated Date' => 'offer_letter_generated_date',
    'Employer Id' => 'employer_id',
    'Employer Name' => 'employer_name',
    'Aggregator' => 'aggregator',
    'Offer Letter Status' => 'offer_letter_status',
    'Aggregator Remarks ICTAK' => 'aggregator_remarks_ictak',
    'Status' => 'status',
];

$flashType = null;
$flashMessage = null;

function normalize_csv_header(string $header): string
{
    $header = strtolower(trim($header));
    return preg_replace('/[^a-z0-9]+/', '', $header) ?? '';
}

function normalize_date_time_value(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s', 'd-m-Y H:i:s', 'd/m/Y H:i:s'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime && $date->format($format) === $value) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

if (is_post()) {
    $file = $_FILES['csv_file'] ?? null;

    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashType = 'danger';
        $flashMessage = 'Please choose a valid CSV file to upload.';
    } else {
        $tmpName = (string) $file['tmp_name'];
        $handle = fopen($tmpName, 'r');

        if ($handle === false) {
            $flashType = 'danger';
            $flashMessage = 'Unable to read the uploaded CSV file.';
        } else {
            $header = fgetcsv($handle);

            if (!is_array($header) || $header === []) {
                $flashType = 'danger';
                $flashMessage = 'CSV appears to be empty or missing a header row.';
            } else {
                $headerMap = [];
                foreach ($header as $index => $columnName) {
                    $headerMap[normalize_csv_header((string) $columnName)] = $index;
                }

                $missingColumns = [];
                foreach (array_keys($requiredColumns) as $requiredColumn) {
                    if (!array_key_exists(normalize_csv_header($requiredColumn), $headerMap)) {
                        $missingColumns[] = $requiredColumn;
                    }
                }

                if ($missingColumns !== []) {
                    $flashType = 'danger';
                    $flashMessage = 'Missing required columns: ' . implode(', ', $missingColumns) . '.';
                } else {
                    $insertSql = 'INSERT INTO aggregator_offer_letter_upload (
                        job_fair_id, job_fair_date, sl_no, dwms_id, candidate_name, job_id, job_role,
                        date_of_issuing_offer_letter, offer_letter_generated, offer_letter_given_to_candidate,
                        link_to_offer_letter, offer_letter_generated_date, employer_id, employer_name,
                        aggregator, offer_letter_status, aggregator_remarks_ictak, status, uploaded_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';

                    $insertStmt = db()->prepare($insertSql);
                    $processedRows = 0;
                    $insertedRows = 0;
                    $skippedRows = 0;

                    while (($row = fgetcsv($handle)) !== false) {
                        $processedRows++;
                        $values = [];

                        foreach ($requiredColumns as $csvColumn => $dbColumn) {
                            $columnIndex = $headerMap[normalize_csv_header($csvColumn)];
                            $values[$dbColumn] = trim((string) ($row[$columnIndex] ?? ''));
                        }

                        if (implode('', $values) === '') {
                            $skippedRows++;
                            continue;
                        }

                        $jobFairDate = normalize_date_time_value($values['job_fair_date']);
                        $issueDate = normalize_date_time_value($values['date_of_issuing_offer_letter']);
                        $generatedDate = normalize_date_time_value($values['offer_letter_generated_date']);

                        if (($values['job_fair_date'] !== '' && $jobFairDate === null)
                            || ($values['date_of_issuing_offer_letter'] !== '' && $issueDate === null)
                            || ($values['offer_letter_generated_date'] !== '' && $generatedDate === null)) {
                            $skippedRows++;
                            continue;
                        }

                        $insertStmt->execute([
                            $values['job_fair_id'],
                            $jobFairDate,
                            $values['sl_no'],
                            $values['dwms_id'],
                            $values['candidate_name'],
                            $values['job_id'],
                            $values['job_role'],
                            $issueDate,
                            $values['offer_letter_generated'],
                            $values['offer_letter_given_to_candidate'],
                            $values['link_to_offer_letter'],
                            $generatedDate,
                            $values['employer_id'],
                            $values['employer_name'],
                            $values['aggregator'],
                            $values['offer_letter_status'],
                            $values['aggregator_remarks_ictak'],
                            $values['status'],
                        ]);

                        $insertedRows++;
                    }

                    $flashType = 'success';
                    $flashMessage = sprintf(
                        'Upload complete. Processed %d rows, inserted %d rows, skipped %d rows.',
                        $processedRows,
                        $insertedRows,
                        $skippedRows
                    );
                }
            }

            fclose($handle);
        }
    }
}

render_header('Upload Aggregator Offer Letter CSV');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Upload Aggregator Offer Letter CSV</h1>
</div>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType ?? 'info') ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-3">
            Upload CSV with required columns in this format:
            <code><?= esc(implode(', ', array_keys($requiredColumns))) ?></code>.
            Data is inserted into <code>aggregator_offer_letter_upload</code>.
        </p>
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <div class="col-12 col-lg-8">
                <label for="csv_file" class="form-label">CSV file</label>
                <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Upload CSV</button>
            </div>
        </form>
    </div>
</div>
<?php render_footer(); ?>
