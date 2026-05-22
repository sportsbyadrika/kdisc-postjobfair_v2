<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$requiredColumns = [
    'Job_Fair_No',
    'Job_Fair_Date',
    'DWMS_ID',
    'Candidate_Name',
    'Employer_ID',
    'Employer_Name',
    'Job_Id',
    'Job_Title_Name',
    'Candidate_District',
    'Mobile_Number',
    'EMail',
    'SDPK',
    'SDPK_District',
    'Aggregator',
    'Aggregator_SPOC_Name',
    'Aggregator_SPOC_Mobile',
    'Employer_SPOC_Name',
    'Employer_SPOC_Mobile',
    'CRM_Member',
    'DSM_Member_1',
    'DSM_Member_2',
    'Category',
    'Selection_Status',
];

$flashType = null;
$flashMessage = null;

function normalize_csv_header(string $header): string
{
    return strtolower(trim($header));
}

function normalize_job_fair_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
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
                foreach ($requiredColumns as $requiredColumn) {
                    if (!array_key_exists(normalize_csv_header($requiredColumn), $headerMap)) {
                        $missingColumns[] = $requiredColumn;
                    }
                }

                if ($missingColumns !== []) {
                    $flashType = 'danger';
                    $flashMessage = 'Missing required columns: ' . implode(', ', $missingColumns) . '.';
                } else {
                    $insertSql = 'INSERT INTO job_fair_result (
                        Job_Fair_No, Job_Fair_Date, DWMS_ID, Candidate_Name, Employer_ID, Employer_Name, Job_Id, Job_Title_Name,
                        Candidate_District, Mobile_Number, EMail, SDPK, SDPK_District, Aggregator, Aggregator_SPOC_Name,
                        Aggregator_SPOC_Mobile, Employer_SPOC_Name, Employer_SPOC_Mobile, CRM_Member, DSM_Member_1,
                        DSM_Member_2, Category, Selection_Status, Data_uploaded_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
                    $insertStmt = db()->prepare($insertSql);

                    $processedRows = 0;
                    $insertedRows = 0;
                    $skippedRows = 0;

                    while (($row = fgetcsv($handle)) !== false) {
                        $processedRows++;

                        $values = [];
                        foreach ($requiredColumns as $requiredColumn) {
                            $columnIndex = $headerMap[normalize_csv_header($requiredColumn)];
                            $values[$requiredColumn] = trim((string) ($row[$columnIndex] ?? ''));
                        }

                        if (implode('', $values) === '') {
                            $skippedRows++;
                            continue;
                        }

                        $normalizedDate = normalize_job_fair_date($values['Job_Fair_Date']);
                        if ($values['Job_Fair_Date'] !== '' && $normalizedDate === null) {
                            $skippedRows++;
                            continue;
                        }

                        $insertStmt->execute([
                            $values['Job_Fair_No'],
                            $normalizedDate,
                            $values['DWMS_ID'],
                            $values['Candidate_Name'],
                            $values['Employer_ID'],
                            $values['Employer_Name'],
                            $values['Job_Id'],
                            $values['Job_Title_Name'],
                            $values['Candidate_District'],
                            $values['Mobile_Number'],
                            $values['EMail'],
                            $values['SDPK'],
                            $values['SDPK_District'],
                            $values['Aggregator'],
                            $values['Aggregator_SPOC_Name'],
                            $values['Aggregator_SPOC_Mobile'],
                            $values['Employer_SPOC_Name'],
                            $values['Employer_SPOC_Mobile'],
                            $values['CRM_Member'],
                            $values['DSM_Member_1'],
                            $values['DSM_Member_2'],
                            $values['Category'],
                            $values['Selection_Status'],
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

render_header('Upload Job Fair Result CSV');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Upload Job Fair Result CSV</h1>
    <a class="btn btn-outline-secondary" href="/aggregator_offer_letter_upload.php">Upload aggregator selected data</a>
</div>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType ?? 'info') ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <p class="text-muted mb-3">
            Upload CSV with these required columns:
            <code><?= esc(implode(', ', $requiredColumns)) ?></code>.
            <strong>Data_uploaded_date</strong> is auto-set to current date & time.
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
