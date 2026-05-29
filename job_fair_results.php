<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();

db()->query(
    "CREATE TABLE IF NOT EXISTS candidate_call_purpose (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purpose_name VARCHAR(255) NOT NULL UNIQUE,
        active_status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

db()->query(
    "CREATE TABLE IF NOT EXISTS candidate_call_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT NOT NULL,
        stage ENUM('Employer Connect','Candidate Connect','Aggregator Contact') NOT NULL,
        call_name VARCHAR(255) DEFAULT NULL,
        call_mobile VARCHAR(255) DEFAULT NULL,
        purpose_id INT DEFAULT NULL,
        call_datetime DATETIME NOT NULL,
        call_status ENUM('Attended','Not attended','Invalid number') NOT NULL,
        call_remarks TEXT,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_candidate_call_history_candidate_id (candidate_id),
        INDEX idx_candidate_call_history_purpose_id (purpose_id),
        INDEX idx_candidate_call_history_created_by (created_by),
        CONSTRAINT fk_candidate_call_history_candidate
            FOREIGN KEY (candidate_id) REFERENCES job_fair_result(id)
            ON DELETE CASCADE,
        CONSTRAINT fk_candidate_call_history_purpose
            FOREIGN KEY (purpose_id) REFERENCES candidate_call_purpose(id)
            ON DELETE SET NULL,
        CONSTRAINT fk_candidate_call_history_created_by
            FOREIGN KEY (created_by) REFERENCES users(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$hasPurposeIdColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'purpose_id'");
$hasPurposeIdColumnRows = $hasPurposeIdColumnStmt->fetchAll();
$hasPurposeIdColumn = $hasPurposeIdColumnRows !== [];
if (!$hasPurposeIdColumn) {
    db()->query("ALTER TABLE candidate_call_history ADD COLUMN purpose_id INT DEFAULT NULL AFTER stage");
    db()->query("ALTER TABLE candidate_call_history ADD INDEX idx_candidate_call_history_purpose_id (purpose_id)");
    db()->query(
        "ALTER TABLE candidate_call_history
            ADD CONSTRAINT fk_candidate_call_history_purpose
                FOREIGN KEY (purpose_id) REFERENCES candidate_call_purpose(id)
                ON DELETE SET NULL"
    );
}

$hasCreatedByColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'created_by'");
$hasCreatedByColumn = $hasCreatedByColumnStmt->fetchAll() !== [];
$hasCallNameColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'call_name'");
$hasCallNameColumn = $hasCallNameColumnStmt->fetchAll() !== [];
if (!$hasCallNameColumn) {
    db()->query("ALTER TABLE candidate_call_history ADD COLUMN call_name VARCHAR(255) DEFAULT NULL AFTER call_remarks");
}

$hasCallMobileColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'call_mobile'");
$hasCallMobileColumn = $hasCallMobileColumnStmt->fetchAll() !== [];
if (!$hasCallMobileColumn) {
    db()->query("ALTER TABLE candidate_call_history ADD COLUMN call_mobile VARCHAR(255) DEFAULT NULL AFTER call_name");
}

$stageColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'stage'");
$stageColumnRows = $stageColumnStmt->fetchAll();
$stageColumn = $stageColumnRows[0] ?? null;
if ($stageColumn && strpos((string) ($stageColumn['Type'] ?? ''), 'Aggregator Contact') === false) {
    db()->query("ALTER TABLE candidate_call_history MODIFY COLUMN stage ENUM('Employer Connect','Candidate Connect','Aggregator Contact') NOT NULL");
}

if (!$hasCreatedByColumn) {
    db()->query("ALTER TABLE candidate_call_history ADD COLUMN created_by INT DEFAULT NULL AFTER call_remarks");
    db()->query("ALTER TABLE candidate_call_history ADD INDEX idx_candidate_call_history_created_by (created_by)");
    db()->query(
        "ALTER TABLE candidate_call_history
            ADD CONSTRAINT fk_candidate_call_history_created_by
                FOREIGN KEY (created_by) REFERENCES users(id)
                ON DELETE SET NULL"
    );
}

db()->query(
    "INSERT INTO candidate_call_purpose (purpose_name)
     VALUES
        ('Follow-up'),
        ('Document Collection'),
        ('Offer Confirmation'),
        ('Joining Coordination')
     ON DUPLICATE KEY UPDATE purpose_name = VALUES(purpose_name)"
);


$hasCategoryColumnStmt = db()->query("SHOW COLUMNS FROM job_fair_result LIKE 'Category'");
$hasCategoryColumn = $hasCategoryColumnStmt->fetchAll() !== [];
if (!$hasCategoryColumn) {
    db()->query("ALTER TABLE job_fair_result ADD COLUMN Category VARCHAR(255) AFTER DSM_Member_2");
}

$jobFairResultColumnDefinitions = [
    'Candidate_Localbody' => "VARCHAR(255) AFTER Candidate_District",
    'Candidate_Jobstation' => "VARCHAR(255) AFTER Candidate_Localbody",
    'Offer_Letter_Generated_Remarks' => "VARCHAR(1000) AFTER Offer_Letter_Generated",
    'Confirm_letter_receipt_remarks' => "VARCHAR(1000) AFTER Confirm_Offer_Letter_Receipt_by_Candidate",
    'willing_to_join_remarks' => "VARCHAR(1000) AFTER Willing_to_Join",
    'Shortlist_remarks' => "VARCHAR(1000) AFTER Shortlist_Candidate_Status",
    'Candidate_Joining_Future_Date' => "DATE AFTER Candidate_Joined_Date",
    'Candidate_Join_Remarks_Type' => "VARCHAR(255) AFTER Remarks_Candidate_Join",
    'Offer_Letter_Salary' => "VARCHAR(255) AFTER Offer_Letter_Join_Date",
];
foreach ($jobFairResultColumnDefinitions as $columnName => $columnDefinition) {
    $escapedColumnName = str_replace("'", "\\'", $columnName);
    $columnStmt = db()->query("SHOW COLUMNS FROM job_fair_result LIKE '{$escapedColumnName}'");
    if ($columnStmt->fetchAll() === []) {
        db()->query("ALTER TABLE job_fair_result ADD COLUMN {$columnName} {$columnDefinition}");
    }
}

$willingToJoinColumnRows = db()->query("SHOW COLUMNS FROM job_fair_result LIKE 'Willing_to_Join'")->fetchAll();
$willingToJoinType = strtolower((string) ($willingToJoinColumnRows[0]['Type'] ?? ''));
if ($willingToJoinType !== '' && (!str_contains($willingToJoinType, "'may be'") || !str_contains($willingToJoinType, "'future date'"))) {
    db()->query("ALTER TABLE job_fair_result MODIFY COLUMN Willing_to_Join ENUM('Yes','No','May be','Future date')");
}

$candidateJoinedStatusColumnRows = db()->query("SHOW COLUMNS FROM job_fair_result LIKE 'Candidate_Joined_Status'")->fetchAll();
$candidateJoinedStatusType = strtolower((string) ($candidateJoinedStatusColumnRows[0]['Type'] ?? ''));
if ($candidateJoinedStatusType !== '' && str_contains($candidateJoinedStatusType, "'not applicable'")) {
    db()->query("ALTER TABLE job_fair_result MODIFY COLUMN Candidate_Joined_Status ENUM('Yes','No','Pending','Future Date')");
}

$shortlistCurrentProcessStatusColumnRows = db()->query("SHOW COLUMNS FROM job_fair_result LIKE 'Shortlist_Current_Process_Status'")->fetchAll();
$shortlistCurrentProcessStatusType = strtolower((string) ($shortlistCurrentProcessStatusColumnRows[0]['Type'] ?? ''));
if ($shortlistCurrentProcessStatusType !== '' && !str_contains($shortlistCurrentProcessStatusType, "'review in progress'")) {
    db()->query("ALTER TABLE job_fair_result MODIFY COLUMN Shortlist_Current_Process_Status ENUM('Completed','Pending','Review in progress')");
}

$shortlistCandidateStatusColumnRows = db()->query("SHOW COLUMNS FROM job_fair_result LIKE 'Shortlist_Candidate_Status'")->fetchAll();
$shortlistCandidateStatusType = strtolower((string) ($shortlistCandidateStatusColumnRows[0]['Type'] ?? ''));
if (
    $shortlistCandidateStatusType !== ''
    && (
        !str_contains($shortlistCandidateStatusType, "'review in progress'")
        || !str_contains($shortlistCandidateStatusType, "'selected for next round'")
        || !str_contains($shortlistCandidateStatusType, "'yet to be contacted'")
    )
) {
    db()->query("ALTER TABLE job_fair_result MODIFY COLUMN Shortlist_Candidate_Status ENUM('Shortlisted','Selected','Rejected','Onhold','Candidate Not Interested','Review in progress','Selected for next round','Yet to be contacted')");
}

db()->query(
    "CREATE TABLE IF NOT EXISTS candidate_manage_activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT NOT NULL,
        activity_section VARCHAR(50) NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        activity_details TEXT,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_candidate_manage_activity_candidate_id (candidate_id),
        INDEX idx_candidate_manage_activity_created_by (created_by),
        CONSTRAINT fk_candidate_manage_activity_candidate
            FOREIGN KEY (candidate_id) REFERENCES job_fair_result(id)
            ON DELETE CASCADE,
        CONSTRAINT fk_candidate_manage_activity_user
            FOREIGN KEY (created_by) REFERENCES users(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);


db()->query(
    "CREATE TABLE IF NOT EXISTS candidate_shortlist_rounds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidate_id INT NOT NULL,
        round_number INT NOT NULL,
        round_scheduled_date DATE NOT NULL,
        round_type ENUM('Interview','Test','Other') NOT NULL,
        round_status ENUM('Pending at Employer','Pending at Candidate','Ongoing','Completed') NOT NULL,
        round_remarks ENUM('Not Scheduled','Candidate not informed','Candidate not interested','Not applicable') DEFAULT NULL,
        round_selection_status ENUM('Selected','Rejected','Pending','Ongoing','Candidate not Attended','Candidate Not Willing','Selected for next round') NOT NULL,
        additional_remarks TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_candidate_shortlist_rounds_candidate_id (candidate_id),
        INDEX idx_candidate_shortlist_rounds_created_by (created_by),
        INDEX idx_candidate_shortlist_rounds_updated_by (updated_by),
        CONSTRAINT fk_candidate_shortlist_rounds_candidate
            FOREIGN KEY (candidate_id) REFERENCES job_fair_result(id)
            ON DELETE CASCADE,
        CONSTRAINT fk_candidate_shortlist_rounds_created_by
            FOREIGN KEY (created_by) REFERENCES users(id)
            ON DELETE SET NULL,
        CONSTRAINT fk_candidate_shortlist_rounds_updated_by
            FOREIGN KEY (updated_by) REFERENCES users(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$shortlistRoundTableColumns = db()->query('SHOW COLUMNS FROM candidate_shortlist_rounds')->fetchAll();
$shortlistRoundColumnMap = [];
foreach ($shortlistRoundTableColumns as $column) {
    $columnName = (string) ($column['Field'] ?? '');
    if ($columnName !== '') {
        $shortlistRoundColumnMap[$columnName] = $column;
    }
}
if (!isset($shortlistRoundColumnMap['additional_remarks'])) {
    db()->query('ALTER TABLE candidate_shortlist_rounds ADD COLUMN additional_remarks TEXT DEFAULT NULL AFTER round_selection_status');
}
$shortlistRoundSelectionStatusType = strtolower((string) ($shortlistRoundColumnMap['round_selection_status']['Type'] ?? ''));
if (
    $shortlistRoundSelectionStatusType !== ''
    && (
        !str_contains($shortlistRoundSelectionStatusType, "'pending'")
        || !str_contains($shortlistRoundSelectionStatusType, "'ongoing'")
        || !str_contains($shortlistRoundSelectionStatusType, "'selected for next round'")
    )
) {
    db()->query("ALTER TABLE candidate_shortlist_rounds MODIFY COLUMN round_selection_status ENUM('Selected','Rejected','Pending','Ongoing','Candidate not Attended','Candidate Not Willing','Selected for next round') NOT NULL");
}

$shortlistRoundTypeOptions = ['Interview', 'Test', 'Other'];
$shortlistRoundStatusOptions = ['Pending at Employer', 'Pending at Candidate', 'Ongoing', 'Completed'];
$shortlistRoundRemarksOptions = ['Not Scheduled', 'Candidate not informed', 'Candidate not interested', 'Not applicable'];
$shortlistRoundSelectionStatusOptions = ['Selected', 'Rejected', 'Pending', 'Ongoing', 'Candidate not Attended', 'Candidate Not Willing', 'Selected for next round'];

function log_candidate_manage_activity(int $candidateId, string $section, string $type, string $details, ?int $userId): void
{
    $logStmt = db()->prepare(
        'INSERT INTO candidate_manage_activity_log (candidate_id, activity_section, activity_type, activity_details, created_by) VALUES (?, ?, ?, ?, ?)'
    );
    $logStmt->execute([
        $candidateId,
        $section,
        $type,
        $details,
        $userId,
    ]);
}

function shortlist_round_post_value(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value === '' ? null : $value;
}

function shortlist_round_value_for_log(?string $value): string
{
    return $value === null || $value === '' ? 'N/A' : $value;
}

$callPurposeOptions = db()->query(
    'SELECT id, purpose_name FROM candidate_call_purpose WHERE active_status = 1 ORDER BY purpose_name'
)->fetchAll();
$callPurposeMap = [];
foreach ($callPurposeOptions as $callPurposeOption) {
    $callPurposeMap[(int) ($callPurposeOption['id'] ?? 0)] = (string) ($callPurposeOption['purpose_name'] ?? '');
}

$editableFieldConfig = [
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Prepratory_Call_Date',
        'field_type' => 'label',
        'group_label' => 'Shortlist Process',
        'row_position' => 1,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Preparatory_Call_Status',
        'field_type' => "enum('Yes','No','Pending')",
        'group_label' => 'Shortlist Process',
        'row_position' => 1,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Next_Process',
        'field_type' => 'varchar',
        'group_label' => 'Shortlist Process',
        'row_position' => 2,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Number_of_Rounds',
        'field_type' => 'varchar',
        'group_label' => 'Shortlist Process',
        'row_position' => 3,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Process_Deadline_Date',
        'field_type' => 'Date time textbox',
        'group_label' => 'Shortlist Process',
        'row_position' => 3,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Current_Call_Status',
        'field_type' => "enum('Yes','No','Pending')",
        'group_label' => 'Shortlist Process',
        'row_position' => 4,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Current_Process_Status',
        'field_type' => "enum('Completed','Pending','Review in progress')",
        'group_label' => 'Shortlist Process',
        'row_position' => 4,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_Candidate_Status',
        'field_type' => "enum('Shortlisted','Selected','Rejected','Onhold','Candidate Not Interested','Review in progress','Selected for next round','Yet to be contacted')",
        'group_label' => 'Shortlist Process',
        'row_position' => 5,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Shortlist/Onhold',
        'field_name' => 'Shortlist_remarks',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Shortlist Process',
        'row_position' => 5,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'First_Call_Date',
        'field_type' => 'Date textbox',
        'group_label' => 'Employer First Call',
        'row_position' => 1,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'First_Call_Done',
        'field_type' => "enum('Yes','No','Pending')",
        'group_label' => 'Employer First Call',
        'row_position' => 1,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Offer_Letter_Generated',
        'field_type' => "enum('Yes','No','Pending')",
        'group_label' => 'Offer Letter Generation',
        'row_position' => 2,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Offer_Letter_Generated_Remarks',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Offer Letter Generation',
        'row_position' => 2,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Offer_Letter_Generated_Date',
        'field_type' => 'Date time textbox',
        'group_label' => 'Offer Letter Generation',
        'row_position' => 2,
        'column_position' => 3,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Link_to_Offer_letter',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Offer Letter Link',
        'row_position' => 1,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Link_to_Offer_letter_verified',
        'field_type' => "enum('Yes','No')",
        'group_label' => 'Offer Letter Link',
        'row_position' => 1,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Offer_Letter_Join_Date',
        'field_type' => 'Date time textbox',
        'group_label' => 'Offer Letter Link',
        'row_position' => 2,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Offer_Letter_Salary',
        'field_type' => 'varchar(255)',
        'group_label' => 'Offer Letter Link',
        'row_position' => 2,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Confirm_Offer_Letter_Receipt_by_Candidate',
        'field_type' => "enum('Yes','No','Pending')",
        'group_label' => 'Offer Confirmation',
        'row_position' => 1,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Confirm_letter_receipt_remarks',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Offer Confirmation',
        'row_position' => 1,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'confirmation_date',
        'field_type' => 'Date time textbox',
        'group_label' => 'Offer Confirmation',
        'row_position' => 2,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Willing_to_Join',
        'field_type' => "enum('Yes','No','May be','Future date')",
        'group_label' => 'Offer Confirmation',
        'row_position' => 2,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'willing_to_join_remarks',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Offer Confirmation',
        'row_position' => 3,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'response_from_employer',
        'field_type' => 'varchar(1000)',
        'group_label' => 'Employer Response',
        'row_position' => 7,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Challenge_Type',
        'field_type' => 'varchar',
        'group_label' => 'Challenges to report',
        'row_position' => 8,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Challenge_to_be_addressed',
        'field_type' => 'varchar',
        'group_label' => 'Challenges to report',
        'row_position' => 8,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Candidate_Joined_Status',
        'field_type' => "enum('Yes','No','Pending','Future Date')",
        'group_label' => 'Candidate Joined details',
        'row_position' => 9,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Candidate_Joined_Date',
        'field_type' => 'Date time textbox',
        'group_label' => 'Candidate Joined details',
        'row_position' => 9,
        'column_position' => 2,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Candidate_Joining_Future_Date',
        'field_type' => 'Date textbox',
        'group_label' => 'Candidate Joined details',
        'row_position' => 9,
        'column_position' => 3,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Remarks_Candidate_Join',
        'field_type' => 'varchar',
        'group_label' => 'Candidate Joined details',
        'row_position' => 10,
        'column_position' => 1,
    ],
    [
        'panel_label' => 'Selected',
        'field_name' => 'Candidate_Join_Remarks_Type',
        'field_type' => "enum('Not Joined - No Reason Specified','Not Interested - General','Not Interested - Location / Relocation','Not Interested - Salary','Not Interested - Accommodation / Food','Not Interested - Got Another Job','Not Interested - Job Mismatch / Field','Exam / Study Related','Personal Reasons','Offer Letter Issues','Not Responding','Interview / Selection Process','Rejected','NAPS / Apprenticeship Related','Medical / Visa Proceedings','Joined','Joining - Confirmed / Upcoming')",
        'group_label' => 'Candidate Joined details',
        'row_position' => 10,
        'column_position' => 2,
    ],
];

$editableFieldMap = [];
foreach ($editableFieldConfig as $config) {
    $editableFieldMap[$config['field_name']] = $config;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $candidateId = (int) ($_POST['candidate_id'] ?? 0);
    $updateSection = trim((string) ($_POST['update_section'] ?? ''));
    $sourceMode = trim((string) ($_POST['source_mode'] ?? ''));
    $isDistrictCandidateDataMode = $sourceMode === 'district_candidate_data';
    $districtCandidateEditableFields = [
        'Confirm_Offer_Letter_Receipt_by_Candidate',
        'Confirm_letter_receipt_remarks',
        'confirmation_date',
        'Offer_Letter_Join_Date',
        'Willing_to_Join',
        'willing_to_join_remarks',
        'Challenge_Type',
        'Challenge_to_be_addressed',
        'Candidate_Joined_Status',
        'Candidate_Joined_Date',
        'Candidate_Joining_Future_Date',
        'Remarks_Candidate_Join',
        'Candidate_Join_Remarks_Type',
    ];

    if ($candidateId > 0) {
        if ($updateSection === 'shortlist_round_save' && !$isDistrictCandidateDataMode) {
            $roundId = (int) ($_POST['shortlist_round_id'] ?? 0);
            $roundNumber = shortlist_round_post_value('shortlist_round_number');
            $roundScheduledDate = shortlist_round_post_value('shortlist_round_scheduled_date');
            $roundType = shortlist_round_post_value('shortlist_round_type');
            $roundStatus = shortlist_round_post_value('shortlist_round_status');
            $roundRemarks = shortlist_round_post_value('shortlist_round_remarks');
            $additionalRemarks = shortlist_round_post_value('shortlist_round_additional_remarks');
            $roundSelectionStatus = shortlist_round_post_value('shortlist_round_selection_status');

            $roundNumberValue = $roundNumber !== null && ctype_digit($roundNumber) && (int) $roundNumber > 0 ? (int) $roundNumber : null;
            $isValidRound = $roundNumberValue !== null
                && $roundScheduledDate !== null
                && in_array($roundType, $shortlistRoundTypeOptions, true)
                && in_array($roundStatus, $shortlistRoundStatusOptions, true)
                && ($roundRemarks === null || in_array($roundRemarks, $shortlistRoundRemarksOptions, true))
                && in_array($roundSelectionStatus, $shortlistRoundSelectionStatusOptions, true);

            if ($isValidRound) {
                if ($roundId > 0) {
                    $existingRoundStmt = db()->prepare('SELECT * FROM candidate_shortlist_rounds WHERE id = ? AND candidate_id = ? LIMIT 1');
                    $existingRoundStmt->execute([$roundId, $candidateId]);
                    $existingRound = $existingRoundStmt->fetch();
                    if ($existingRound) {
                        $updateRoundStmt = db()->prepare(
                            'UPDATE candidate_shortlist_rounds
                             SET round_number = ?, round_scheduled_date = ?, round_type = ?, round_status = ?, round_remarks = ?, round_selection_status = ?, additional_remarks = ?, updated_by = ?
                             WHERE id = ? AND candidate_id = ?'
                        );
                        $updateRoundStmt->execute([
                            $roundNumberValue,
                            $roundScheduledDate,
                            $roundType,
                            $roundStatus,
                            $roundRemarks,
                            $roundSelectionStatus,
                            $additionalRemarks,
                            (int) ($user['id'] ?? 0),
                            $roundId,
                            $candidateId,
                        ]);

                        $roundChanges = [];
                        $roundFields = [
                            'round_number' => ['label' => 'Round number', 'new' => (string) $roundNumberValue],
                            'round_scheduled_date' => ['label' => 'Scheduled date', 'new' => $roundScheduledDate],
                            'round_type' => ['label' => 'Round type', 'new' => $roundType],
                            'round_status' => ['label' => 'Round status', 'new' => $roundStatus],
                            'round_remarks' => ['label' => 'Round remarks', 'new' => $roundRemarks],
                            'round_selection_status' => ['label' => 'Selection status', 'new' => $roundSelectionStatus],
                            'additional_remarks' => ['label' => 'Remarks', 'new' => $additionalRemarks],
                        ];
                        foreach ($roundFields as $field => $fieldMeta) {
                            $oldValue = isset($existingRound[$field]) ? (string) $existingRound[$field] : null;
                            $newValue = $fieldMeta['new'];
                            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                                continue;
                            }
                            $roundChanges[] = $fieldMeta['label']
                                . ': ' . shortlist_round_value_for_log($oldValue)
                                . ' -> ' . shortlist_round_value_for_log($newValue);
                        }

                        if ($roundChanges !== []) {
                            log_candidate_manage_activity(
                                $candidateId,
                                'shortlist_rounds',
                                'update',
                                'Round ID: ' . $roundId . "\n" . implode("\n", $roundChanges),
                                (int) ($user['id'] ?? 0)
                            );
                        }
                    }
                } else {
                    $insertRoundStmt = db()->prepare(
                        'INSERT INTO candidate_shortlist_rounds (candidate_id, round_number, round_scheduled_date, round_type, round_status, round_remarks, round_selection_status, additional_remarks, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insertRoundStmt->execute([
                        $candidateId,
                        $roundNumberValue,
                        $roundScheduledDate,
                        $roundType,
                        $roundStatus,
                        $roundRemarks,
                        $roundSelectionStatus,
                        $additionalRemarks,
                        (int) ($user['id'] ?? 0),
                        (int) ($user['id'] ?? 0),
                    ]);

                    log_candidate_manage_activity(
                        $candidateId,
                        'shortlist_rounds',
                        'save',
                        'Round number: ' . $roundNumberValue
                        . "\nScheduled date: " . $roundScheduledDate
                        . "\nRound type: " . $roundType
                        . "\nRound status: " . $roundStatus
                        . "\nRound remarks: " . shortlist_round_value_for_log($roundRemarks)
                        . "\nRemarks: " . shortlist_round_value_for_log($additionalRemarks)
                        . "\nSelection status: " . $roundSelectionStatus,
                        (int) ($user['id'] ?? 0)
                    );
                }
            }
        }

        $setClauses = [];
        $updateValues = [];

        foreach ($editableFieldMap as $fieldName => $fieldConfig) {
            if (($fieldConfig['field_type'] ?? '') === 'label') {
                continue;
            }
            if ($isDistrictCandidateDataMode && !in_array($fieldName, $districtCandidateEditableFields, true)) {
                continue;
            }

            $panelLabel = (string) ($fieldConfig['panel_label'] ?? '');
            if ($updateSection === 'shortlist_onhold' && $panelLabel !== 'Shortlist/Onhold') {
                continue;
            }
            if ($updateSection === 'selected' && $panelLabel !== 'Selected') {
                continue;
            }
            if (!in_array($updateSection, ['shortlist_onhold', 'selected', ''], true)) {
                continue;
            }

            $value = trim((string) ($_POST[$fieldName] ?? ''));
            $value = $value === '' ? null : $value;
            $setClauses[] = "$fieldName = ?";
            $updateValues[] = $value;
        }

        if ($setClauses !== []) {
            $beforeStmt = db()->prepare('SELECT * FROM job_fair_result WHERE id = ?');
            $beforeStmt->execute([$candidateId]);
            $beforeRow = $beforeStmt->fetch() ?: [];

            $updateValues[] = $candidateId;
            $updateSql = 'UPDATE job_fair_result SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $updateStmt = db()->prepare($updateSql);
            $updateStmt->execute($updateValues);

            $changeLogs = [];
            foreach ($editableFieldMap as $fieldName => $fieldConfig) {
                if (($fieldConfig['field_type'] ?? '') === 'label') {
                    continue;
                }
                if ($isDistrictCandidateDataMode && !in_array($fieldName, $districtCandidateEditableFields, true)) {
                    continue;
                }
                $panelLabel = (string) ($fieldConfig['panel_label'] ?? '');
                if ($updateSection === 'shortlist_onhold' && $panelLabel !== 'Shortlist/Onhold') {
                    continue;
                }
                if ($updateSection === 'selected' && $panelLabel !== 'Selected') {
                    continue;
                }
                if (!in_array($updateSection, ['shortlist_onhold', 'selected', ''], true)) {
                    continue;
                }

                $oldValue = $beforeRow[$fieldName] ?? null;
                $newValue = trim((string) ($_POST[$fieldName] ?? ''));
                $newValue = $newValue === '' ? null : $newValue;
                if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                    continue;
                }

                $changeLogs[] = str_replace('_', ' ', $fieldName)
                    . ': ' . (($oldValue === null || $oldValue === '') ? 'N/A' : (string) $oldValue)
                    . ' -> ' . (($newValue === null || $newValue === '') ? 'N/A' : (string) $newValue);
            }

            if ($changeLogs !== []) {
                $sectionName = $updateSection === 'selected' ? 'selected' : 'shortlist_onhold';
                log_candidate_manage_activity($candidateId, $sectionName, 'update', implode("\n", $changeLogs), (int) ($user['id'] ?? 0));
            }
        }

        if ($updateSection === 'call_history' || ($updateSection === '' && !$isDistrictCandidateDataMode)) {
            $callHistoryStage = trim((string) ($_POST['call_history_stage'] ?? ''));
            $rowStmt = db()->prepare('SELECT Candidate_Name, Mobile_number, Employer_SPOC_Name, Employer_SPOC_Mobile, Aggregator_SPOC_Name, Aggregator_Spoc_mobile FROM job_fair_result WHERE id = ? LIMIT 1');
            $rowStmt->execute([$candidateId]);
            $callContactRow = $rowStmt->fetch() ?: [];
            $callName = null;
            $callMobile = null;
            if ($callHistoryStage === 'Employer Connect') {
                $callName = trim((string) ($callContactRow['Employer_SPOC_Name'] ?? ''));
                $callMobile = trim((string) ($callContactRow['Employer_SPOC_Mobile'] ?? ''));
            } elseif ($callHistoryStage === 'Aggregator Contact') {
                $callName = trim((string) ($callContactRow['Aggregator_SPOC_Name'] ?? ''));
                $callMobile = trim((string) ($callContactRow['Aggregator_Spoc_mobile'] ?? ''));
            } elseif ($callHistoryStage === 'Candidate Connect') {
                $callName = trim((string) ($callContactRow['Candidate_Name'] ?? ''));
                $callMobile = trim((string) ($callContactRow['Mobile_number'] ?? ''));
            }
            $callName = $callName === '' ? null : $callName;
            $callMobile = $callMobile === '' ? null : $callMobile;
            $callHistoryPurposeId = (int) ($_POST['call_history_purpose_id'] ?? 0);
            $callHistoryDateTime = trim((string) ($_POST['call_history_call_datetime'] ?? ''));
            $callHistoryStatus = trim((string) ($_POST['call_history_call_status'] ?? ''));
            $callHistoryRemarks = trim((string) ($_POST['call_history_call_remarks'] ?? ''));

            $validCallHistoryPurposeId = null;
            if ($callHistoryPurposeId > 0) {
                $purposeStmt = db()->prepare('SELECT id FROM candidate_call_purpose WHERE id = ? AND active_status = 1');
                $purposeStmt->execute([$callHistoryPurposeId]);
                $validCallHistoryPurposeId = $purposeStmt->fetchColumn() !== false ? $callHistoryPurposeId : null;
            }

            if ($callHistoryStage !== '' && $callHistoryStatus !== '' && $callHistoryDateTime !== '') {
                $callHistoryStmt = db()->prepare(
                    'INSERT INTO candidate_call_history (candidate_id, stage, purpose_id, call_datetime, call_status, call_remarks, call_name, call_mobile, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $callHistoryStmt->execute([
                    $candidateId,
                    $callHistoryStage,
                    $validCallHistoryPurposeId,
                    str_replace('T', ' ', $callHistoryDateTime),
                    $callHistoryStatus,
                    $callHistoryRemarks === '' ? null : $callHistoryRemarks,
                    $callName,
                    $callMobile,
                    (int) ($user['id'] ?? 0),
                ]);

                log_candidate_manage_activity(
                    $candidateId,
                    'call_history',
                    'save',
                    'Stage: ' . $callHistoryStage
                    . "\nPurpose: " . (($validCallHistoryPurposeId === null) ? 'N/A' : ($callPurposeMap[$validCallHistoryPurposeId] ?? 'N/A'))
                    . "\nStatus: " . $callHistoryStatus
                    . "\nDate time: " . str_replace('T', ' ', $callHistoryDateTime)
                    . "\nRemarks: " . ($callHistoryRemarks === '' ? 'N/A' : $callHistoryRemarks),
                    (int) ($user['id'] ?? 0)
                );
            }
        }
    }

    $modalCandidateId = (int) ($_POST['modal_candidate_id'] ?? $candidateId);
    $modalActiveTab = trim((string) ($_POST['modal_active_tab'] ?? ''));

    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $baseParams = [];
    if ($queryString !== '') {
        parse_str($queryString, $baseParams);
    }

    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo === 'manage_candidate.php') {
        $manageParams = [];
        $returnQuery = trim((string) ($_POST['return_query'] ?? ''));
        if ($returnQuery !== '') {
            parse_str($returnQuery, $manageParams);
        }
        if ($modalCandidateId > 0) {
            $manageParams['candidate_id'] = $modalCandidateId;
        }
        if ($modalActiveTab !== '') {
            $manageParams['tab'] = $modalActiveTab;
        }
        $redirectTarget = '/manage_candidate.php' . ($manageParams !== [] ? ('?' . http_build_query($manageParams)) : '');
    } else {
        if ($modalCandidateId > 0) {
            $baseParams['manage_candidate_id'] = $modalCandidateId;
        }
        if ($modalActiveTab !== '') {
            $baseParams['manage_candidate_tab'] = $modalActiveTab;
        }
        $redirectTarget = '/job_fair_results.php' . ($baseParams !== [] ? ('?' . http_build_query($baseParams)) : '');
    }

    header('Location: ' . $redirectTarget);
    exit;
}

if (isset($_GET['candidate_call_history'])) {
    $candidateId = (int) ($_GET['candidate_call_history'] ?? 0);
    $historyStmt = db()->prepare(
        'SELECT h.id, h.stage, h.call_datetime, h.call_status, h.call_remarks, COALESCE(p.purpose_name, \'\') AS purpose_name
         FROM candidate_call_history h
         LEFT JOIN candidate_call_purpose p ON p.id = h.purpose_id
         WHERE h.candidate_id = ?
         ORDER BY h.call_datetime DESC, h.id DESC'
    );
    $historyStmt->execute([$candidateId]);

    header('Content-Type: application/json');
    echo json_encode($historyStmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['candidate_shortlist_rounds'])) {
    $candidateId = (int) ($_GET['candidate_shortlist_rounds'] ?? 0);
    $roundStmt = db()->prepare(
        'SELECT id, round_number, round_scheduled_date, round_type, round_status, round_remarks, round_selection_status, additional_remarks, created_at, updated_at
         FROM candidate_shortlist_rounds
         WHERE candidate_id = ?
         ORDER BY round_number ASC, round_scheduled_date ASC, id ASC'
    );
    $roundStmt->execute([$candidateId]);

    header('Content-Type: application/json');
    echo json_encode($roundStmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['candidate_manage_activity_log'])) {
    $candidateId = (int) ($_GET['candidate_manage_activity_log'] ?? 0);
    $activityStmt = db()->prepare(
        'SELECT l.id, l.activity_section, l.activity_type, l.activity_details, l.created_at, u.name AS created_by_name FROM candidate_manage_activity_log l LEFT JOIN users u ON u.id = l.created_by WHERE l.candidate_id = ? ORDER BY l.created_at DESC, l.id DESC'
    );
    $activityStmt->execute([$candidateId]);

    header('Content-Type: application/json');
    echo json_encode($activityStmt->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['candidate_row'])) {
    $candidateId = (int) ($_GET['candidate_row'] ?? 0);
    $candidateStmt = db()->prepare('SELECT * FROM job_fair_result WHERE id = ? LIMIT 1');
    $candidateStmt->execute([$candidateId]);

    header('Content-Type: application/json');
    echo json_encode($candidateStmt->fetch() ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['manage_candidate_meta'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'field_config' => $editableFieldConfig,
        'call_purpose_options' => $callPurposeOptions,
        'shortlist_round_options' => [
            'round_type' => $shortlistRoundTypeOptions,
            'round_status' => $shortlistRoundStatusOptions,
            'round_remarks' => $shortlistRoundRemarksOptions,
            'round_selection_status' => $shortlistRoundSelectionStatusOptions,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$selectionStatusParamProvided = array_key_exists('selection_status', $_GET);
$rawSelectionStatusFilter = $_GET['selection_status'] ?? [];
if (!is_array($rawSelectionStatusFilter)) {
    $rawSelectionStatusFilter = $rawSelectionStatusFilter === '' ? [] : [$rawSelectionStatusFilter];
}
$selectionStatusFilters = array_values(array_filter(
    array_map(static fn($v): string => trim((string) $v), $rawSelectionStatusFilter),
    static fn(string $v): bool => $v !== ''
));
$jobFairNoFilter = trim($_GET['job_fair_no'] ?? '');
$dwmsIdFilter = trim($_GET['dwms_id'] ?? '');
$candidateNameFilter = trim($_GET['candidate_name'] ?? '');
$aggregatorFilter = trim($_GET['aggregator'] ?? '');
$employerNameFilter = trim($_GET['employer_name'] ?? '');
$crmMemberFilterProvided = array_key_exists('crm_member', $_GET);
$crmMemberFilter = trim($_GET['crm_member'] ?? '');
$dsmMember1Filter = trim($_GET['dsm_member_1'] ?? '');
$dsmMember2Filter = trim($_GET['dsm_member_2'] ?? '');
$shortlistPreparatoryCallStatusFilter = trim($_GET['shortlist_preparatory_call_status'] ?? '');
$shortlistCurrentCallStatusFilter = trim($_GET['shortlist_current_call_status'] ?? '');
$shortlistCurrentProcessStatusFilter = trim($_GET['shortlist_current_process_status'] ?? '');
$shortlistCandidateStatusFilter = trim($_GET['shortlist_candidate_status'] ?? '');
$firstCallDoneFilter = trim($_GET['first_call_done'] ?? '');
$offerLetterGeneratedFilter = trim($_GET['offer_letter_generated'] ?? '');
$linkToOfferLetterVerifiedFilter = trim($_GET['link_to_offer_letter_verified'] ?? '');
$confirmOfferLetterReceiptByCandidateFilter = trim($_GET['confirm_offer_letter_receipt_by_candidate'] ?? '');
$candidateJoinedStatusFilter = trim($_GET['candidate_joined_status'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$manageCandidateId = (int) ($_GET['manage_candidate_id'] ?? 0);
$manageCandidateTab = trim($_GET['manage_candidate_tab'] ?? '');
$currentQueryString = $_SERVER['QUERY_STRING'] ?? '';
$page = max((int) ($_GET['page'] ?? 1), 1);
$perPage = 25;

$selectionStatuses = db()->query("SELECT DISTINCT Selection_Status FROM job_fair_result WHERE Selection_Status IS NOT NULL AND Selection_Status <> '' ORDER BY Selection_Status")->fetchAll();

// First load (no selection_status param in URL): pre-tick "Selected",
// "Shortlisted" and any On Hold variant (matched case-insensitively against
// the distinct list so whichever spelling exists in the data wins).
if (!$selectionStatusParamProvided) {
    foreach ($selectionStatuses as $statusRow) {
        $value = (string) $statusRow['Selection_Status'];
        $key = strtolower(str_replace(' ', '', $value));
        if (in_array($key, ['selected', 'shortlisted', 'onhold'], true)) {
            $selectionStatusFilters[] = $value;
        }
    }
}
$jobFairNos = db()->query("SELECT DISTINCT Job_Fair_No FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND Job_Fair_No <> '' ORDER BY Job_Fair_No DESC")->fetchAll();
$employerNames = db()->query("SELECT DISTINCT Employer_Name FROM job_fair_result WHERE Employer_Name IS NOT NULL AND Employer_Name <> '' ORDER BY Employer_Name")->fetchAll();
$aggregators = db()->query("SELECT DISTINCT Aggregator FROM job_fair_result WHERE Aggregator IS NOT NULL AND Aggregator <> '' ORDER BY Aggregator")->fetchAll();
$crmMembers = db()->query("SELECT DISTINCT CRM_Member FROM job_fair_result WHERE CRM_Member IS NOT NULL AND CRM_Member <> '' ORDER BY CRM_Member")->fetchAll();

// On first load (no crm_member param in URL), if the logged-in user's name
// matches a CRM Member value, default-select them so a CRM user lands
// directly on their own caseload.
if (!$crmMemberFilterProvided) {
    $userName = trim((string) ($user['name'] ?? ''));
    if ($userName !== '') {
        foreach ($crmMembers as $cm) {
            if (trim((string) ($cm['CRM_Member'] ?? '')) === $userName) {
                $crmMemberFilter = $userName;
                break;
            }
        }
    }
}
$dsmMember1s = db()->query("SELECT DISTINCT DSM_Member_1 FROM job_fair_result WHERE DSM_Member_1 IS NOT NULL AND DSM_Member_1 <> '' ORDER BY DSM_Member_1")->fetchAll();
$dsmMember2s = db()->query("SELECT DISTINCT DSM_Member_2 FROM job_fair_result WHERE DSM_Member_2 IS NOT NULL AND DSM_Member_2 <> '' ORDER BY DSM_Member_2")->fetchAll();
$shortlistPreparatoryCallStatuses = db()->query("SELECT DISTINCT Shortlist_Preparatory_Call_Status FROM job_fair_result WHERE Shortlist_Preparatory_Call_Status IS NOT NULL AND Shortlist_Preparatory_Call_Status <> '' ORDER BY Shortlist_Preparatory_Call_Status")->fetchAll();
$shortlistCurrentCallStatuses = db()->query("SELECT DISTINCT Shortlist_Current_Call_Status FROM job_fair_result WHERE Shortlist_Current_Call_Status IS NOT NULL AND Shortlist_Current_Call_Status <> '' ORDER BY Shortlist_Current_Call_Status")->fetchAll();
$shortlistCurrentProcessStatuses = db()->query("SELECT DISTINCT Shortlist_Current_Process_Status FROM job_fair_result WHERE Shortlist_Current_Process_Status IS NOT NULL AND Shortlist_Current_Process_Status <> '' ORDER BY Shortlist_Current_Process_Status")->fetchAll();
$shortlistCandidateStatuses = db()->query("SELECT DISTINCT Shortlist_Candidate_Status FROM job_fair_result WHERE Shortlist_Candidate_Status IS NOT NULL AND Shortlist_Candidate_Status <> '' ORDER BY Shortlist_Candidate_Status")->fetchAll();
$firstCallDoneStatuses = db()->query("SELECT DISTINCT First_Call_Done FROM job_fair_result WHERE First_Call_Done IS NOT NULL AND First_Call_Done <> '' ORDER BY First_Call_Done")->fetchAll();
$offerLetterGeneratedStatuses = db()->query("SELECT DISTINCT Offer_Letter_Generated FROM job_fair_result WHERE Offer_Letter_Generated IS NOT NULL AND Offer_Letter_Generated <> '' ORDER BY Offer_Letter_Generated")->fetchAll();
$linkToOfferLetterVerifiedStatuses = db()->query("SELECT DISTINCT Link_to_Offer_letter_verified FROM job_fair_result WHERE Link_to_Offer_letter_verified IS NOT NULL AND Link_to_Offer_letter_verified <> '' ORDER BY Link_to_Offer_letter_verified")->fetchAll();
$confirmOfferLetterReceiptByCandidateStatuses = db()->query("SELECT DISTINCT Confirm_Offer_Letter_Receipt_by_Candidate FROM job_fair_result WHERE Confirm_Offer_Letter_Receipt_by_Candidate IS NOT NULL AND Confirm_Offer_Letter_Receipt_by_Candidate <> '' ORDER BY Confirm_Offer_Letter_Receipt_by_Candidate")->fetchAll();
$candidateJoinedStatuses = db()->query("SELECT DISTINCT Candidate_Joined_Status FROM job_fair_result WHERE Candidate_Joined_Status IS NOT NULL AND Candidate_Joined_Status <> '' ORDER BY Candidate_Joined_Status")->fetchAll();
$categories = db()->query("SELECT DISTINCT Category FROM job_fair_result WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category")->fetchAll();

$whereSql = ' FROM job_fair_result WHERE 1=1';
$params = [];

if ($selectionStatusFilters !== []) {
    $placeholders = implode(',', array_fill(0, count($selectionStatusFilters), '?'));
    $whereSql .= " AND Selection_Status IN ($placeholders)";
    foreach ($selectionStatusFilters as $sv) {
        $params[] = $sv;
    }
}
if ($jobFairNoFilter !== '') {
    $whereSql .= ' AND Job_Fair_No = ?';
    $params[] = $jobFairNoFilter;
}
if ($dwmsIdFilter !== '') {
    $whereSql .= ' AND DWMS_ID LIKE ?';
    $params[] = '%' . $dwmsIdFilter . '%';
}
if ($candidateNameFilter !== '') {
    $whereSql .= ' AND Candidate_Name LIKE ?';
    $params[] = '%' . $candidateNameFilter . '%';
}
if ($aggregatorFilter !== '') {
    $whereSql .= ' AND Aggregator = ?';
    $params[] = $aggregatorFilter;
}
if ($employerNameFilter !== '') {
    $whereSql .= ' AND Employer_Name = ?';
    $params[] = $employerNameFilter;
}
if ($crmMemberFilter !== '') {
    $whereSql .= ' AND CRM_Member = ?';
    $params[] = $crmMemberFilter;
}
if ($dsmMember1Filter !== '') {
    $whereSql .= ' AND DSM_Member_1 = ?';
    $params[] = $dsmMember1Filter;
}
if ($dsmMember2Filter !== '') {
    $whereSql .= ' AND DSM_Member_2 = ?';
    $params[] = $dsmMember2Filter;
}
if ($shortlistPreparatoryCallStatusFilter !== '') {
    $whereSql .= ' AND Shortlist_Preparatory_Call_Status = ?';
    $params[] = $shortlistPreparatoryCallStatusFilter;
}
if ($shortlistCurrentCallStatusFilter !== '') {
    $whereSql .= ' AND Shortlist_Current_Call_Status = ?';
    $params[] = $shortlistCurrentCallStatusFilter;
}
if ($shortlistCurrentProcessStatusFilter !== '') {
    $whereSql .= ' AND Shortlist_Current_Process_Status = ?';
    $params[] = $shortlistCurrentProcessStatusFilter;
}
if ($shortlistCandidateStatusFilter !== '') {
    $whereSql .= ' AND Shortlist_Candidate_Status = ?';
    $params[] = $shortlistCandidateStatusFilter;
}
if ($firstCallDoneFilter !== '') {
    $whereSql .= ' AND First_Call_Done = ?';
    $params[] = $firstCallDoneFilter;
}
if ($offerLetterGeneratedFilter !== '') {
    $whereSql .= ' AND Offer_Letter_Generated = ?';
    $params[] = $offerLetterGeneratedFilter;
}
if ($linkToOfferLetterVerifiedFilter !== '') {
    $whereSql .= ' AND Link_to_Offer_letter_verified = ?';
    $params[] = $linkToOfferLetterVerifiedFilter;
}
if ($confirmOfferLetterReceiptByCandidateFilter !== '') {
    $whereSql .= ' AND Confirm_Offer_Letter_Receipt_by_Candidate = ?';
    $params[] = $confirmOfferLetterReceiptByCandidateFilter;
}
if ($candidateJoinedStatusFilter !== '') {
    $whereSql .= ' AND Candidate_Joined_Status = ?';
    $params[] = $candidateJoinedStatusFilter;
}
if ($categoryFilter !== '') {
    $whereSql .= ' AND Category = ?';
    $params[] = $categoryFilter;
}
$countStmt = db()->prepare('SELECT COUNT(*)' . $whereSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max((int) ceil($totalRecords / $perPage), 1);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT *' . $whereSql . ' ORDER BY Data_uploaded_date DESC, id DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$queryParams = [...$params, $perPage, $offset];
$stmt->execute($queryParams);
$rows = $stmt->fetchAll();

render_header('Job fair result data', ['main_container_class' => 'container-fluid']);
$resultsActions = '<span class="status-chip status-info me-1">' . (int) $totalRecords . ' records</span>';
if ($user['role'] === 'administrator') {
    $resultsActions .= '<a class="btn btn-light" href="/job_fair_result_upload.php"><i class="bi bi-upload me-1"></i>Upload CSV</a>';
}
render_page_header('Job Fair Result Data', [
    'icon' => 'bi-table',
    'subtitle' => 'Search, filter and manage post job fair candidate records.',
    'actions' => $resultsActions,
]);
?>

<?php
$advancedFiltersActive = ($dsmMember1Filter !== '')
    || ($dsmMember2Filter !== '')
    || ($shortlistPreparatoryCallStatusFilter !== '')
    || ($shortlistCurrentCallStatusFilter !== '')
    || ($shortlistCurrentProcessStatusFilter !== '')
    || ($firstCallDoneFilter !== '')
    || ($offerLetterGeneratedFilter !== '')
    || ($linkToOfferLetterVerifiedFilter !== '')
    || ($confirmOfferLetterReceiptByCandidateFilter !== '')
    || ($candidateJoinedStatusFilter !== '');
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h2 class="h5 mb-0"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
            <button class="btn btn-sm btn-link text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="<?= $advancedFiltersActive ? 'true' : 'false' ?>">
                <i class="bi bi-sliders me-1"></i>Advanced filters
            </button>
        </div>
        <div class="border rounded p-2 mb-3 bg-light-subtle">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span class="form-label mb-0 me-1"><i class="bi bi-ui-checks me-1"></i>Selection Status</span>
                <?php foreach ($selectionStatuses as $status): ?>
                    <?php $sv = (string) $status['Selection_Status']; $cbId = 'selstat-' . md5($sv); ?>
                    <div class="form-check form-check-inline mb-0">
                        <input class="form-check-input" type="checkbox" name="selection_status[]" value="<?= esc($sv) ?>" id="<?= esc($cbId) ?>" <?= in_array($sv, $selectionStatusFilters, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= esc($cbId) ?>"><?= esc($sv) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Shortlist Candidate Status</label>
                <select class="form-select" name="shortlist_candidate_status">
                    <option value="">All</option>
                    <?php foreach ($shortlistCandidateStatuses as $status): ?>
                        <option value="<?= esc($status['Shortlist_Candidate_Status']) ?>" <?= $shortlistCandidateStatusFilter === $status['Shortlist_Candidate_Status'] ? 'selected' : '' ?>><?= esc($status['Shortlist_Candidate_Status']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Job Fair No</label>
                <select class="form-select" name="job_fair_no">
                    <option value="">All</option>
                    <?php foreach ($jobFairNos as $jobFairNo): ?>
                        <option value="<?= esc($jobFairNo['Job_Fair_No']) ?>" <?= $jobFairNoFilter === $jobFairNo['Job_Fair_No'] ? 'selected' : '' ?>><?= esc($jobFairNo['Job_Fair_No']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Employer Name</label>
                <select class="form-select" name="employer_name">
                    <option value="">All</option>
                    <?php foreach ($employerNames as $employerName): ?>
                        <option value="<?= esc($employerName['Employer_Name']) ?>" <?= $employerNameFilter === $employerName['Employer_Name'] ? 'selected' : '' ?>><?= esc($employerName['Employer_Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">CRM Member</label>
                <select class="form-select" name="crm_member">
                    <option value="">All</option>
                    <?php foreach ($crmMembers as $crmMember): ?>
                        <option value="<?= esc($crmMember['CRM_Member']) ?>" <?= $crmMemberFilter === $crmMember['CRM_Member'] ? 'selected' : '' ?>><?= esc($crmMember['CRM_Member']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">DWMS ID</label>
                <input class="form-control" name="dwms_id" type="text" value="<?= esc($dwmsIdFilter) ?>" placeholder="Enter DWMS ID">
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Candidate Name</label>
                <input class="form-control" name="candidate_name" type="text" value="<?= esc($candidateNameFilter) ?>" placeholder="Enter candidate name">
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Aggregator</label>
                <select class="form-select" name="aggregator">
                    <option value="">All</option>
                    <?php foreach ($aggregators as $aggregator): ?>
                        <option value="<?= esc($aggregator['Aggregator']) ?>" <?= $aggregatorFilter === $aggregator['Aggregator'] ? 'selected' : '' ?>><?= esc($aggregator['Aggregator']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= esc($category['Category']) ?>" <?= $categoryFilter === $category['Category'] ? 'selected' : '' ?>><?= esc($category['Category']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="collapse <?= $advancedFiltersActive ? 'show' : '' ?>" id="advancedFilters">
            <hr class="my-3">
            <div class="row g-3">
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">DSM Member 1</label>
                    <select class="form-select" name="dsm_member_1">
                        <option value="">All</option>
                        <?php foreach ($dsmMember1s as $dsmMember1): ?>
                            <option value="<?= esc($dsmMember1['DSM_Member_1']) ?>" <?= $dsmMember1Filter === $dsmMember1['DSM_Member_1'] ? 'selected' : '' ?>><?= esc($dsmMember1['DSM_Member_1']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">DSM Member 2</label>
                    <select class="form-select" name="dsm_member_2">
                        <option value="">All</option>
                        <?php foreach ($dsmMember2s as $dsmMember2): ?>
                            <option value="<?= esc($dsmMember2['DSM_Member_2']) ?>" <?= $dsmMember2Filter === $dsmMember2['DSM_Member_2'] ? 'selected' : '' ?>><?= esc($dsmMember2['DSM_Member_2']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Shortlist Preparatory Call Status</label>
                    <select class="form-select" name="shortlist_preparatory_call_status">
                        <option value="">All</option>
                        <?php foreach ($shortlistPreparatoryCallStatuses as $status): ?>
                            <option value="<?= esc($status['Shortlist_Preparatory_Call_Status']) ?>" <?= $shortlistPreparatoryCallStatusFilter === $status['Shortlist_Preparatory_Call_Status'] ? 'selected' : '' ?>><?= esc($status['Shortlist_Preparatory_Call_Status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Shortlist Current Call Status</label>
                    <select class="form-select" name="shortlist_current_call_status">
                        <option value="">All</option>
                        <?php foreach ($shortlistCurrentCallStatuses as $status): ?>
                            <option value="<?= esc($status['Shortlist_Current_Call_Status']) ?>" <?= $shortlistCurrentCallStatusFilter === $status['Shortlist_Current_Call_Status'] ? 'selected' : '' ?>><?= esc($status['Shortlist_Current_Call_Status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Shortlist Current Process Status</label>
                    <select class="form-select" name="shortlist_current_process_status">
                        <option value="">All</option>
                        <?php foreach ($shortlistCurrentProcessStatuses as $status): ?>
                            <option value="<?= esc($status['Shortlist_Current_Process_Status']) ?>" <?= $shortlistCurrentProcessStatusFilter === $status['Shortlist_Current_Process_Status'] ? 'selected' : '' ?>><?= esc($status['Shortlist_Current_Process_Status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">First Call Done</label>
                    <select class="form-select" name="first_call_done">
                        <option value="">All</option>
                        <?php foreach ($firstCallDoneStatuses as $status): ?>
                            <option value="<?= esc($status['First_Call_Done']) ?>" <?= $firstCallDoneFilter === $status['First_Call_Done'] ? 'selected' : '' ?>><?= esc($status['First_Call_Done']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Offer Letter Generated</label>
                    <select class="form-select" name="offer_letter_generated">
                        <option value="">All</option>
                        <?php foreach ($offerLetterGeneratedStatuses as $status): ?>
                            <option value="<?= esc($status['Offer_Letter_Generated']) ?>" <?= $offerLetterGeneratedFilter === $status['Offer_Letter_Generated'] ? 'selected' : '' ?>><?= esc($status['Offer_Letter_Generated']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Link to Offer Letter Verified</label>
                    <select class="form-select" name="link_to_offer_letter_verified">
                        <option value="">All</option>
                        <?php foreach ($linkToOfferLetterVerifiedStatuses as $status): ?>
                            <option value="<?= esc($status['Link_to_Offer_letter_verified']) ?>" <?= $linkToOfferLetterVerifiedFilter === $status['Link_to_Offer_letter_verified'] ? 'selected' : '' ?>><?= esc($status['Link_to_Offer_letter_verified']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Confirm Offer Letter Receipt by Candidate</label>
                    <select class="form-select" name="confirm_offer_letter_receipt_by_candidate">
                        <option value="">All</option>
                        <?php foreach ($confirmOfferLetterReceiptByCandidateStatuses as $status): ?>
                            <option value="<?= esc($status['Confirm_Offer_Letter_Receipt_by_Candidate']) ?>" <?= $confirmOfferLetterReceiptByCandidateFilter === $status['Confirm_Offer_Letter_Receipt_by_Candidate'] ? 'selected' : '' ?>><?= esc($status['Confirm_Offer_Letter_Receipt_by_Candidate']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-2">
                    <label class="form-label">Candidate Joined Status</label>
                    <select class="form-select" name="candidate_joined_status">
                        <option value="">All</option>
                        <?php foreach ($candidateJoinedStatuses as $status): ?>
                            <option value="<?= esc($status['Candidate_Joined_Status']) ?>" <?= $candidateJoinedStatusFilter === $status['Candidate_Joined_Status'] ? 'selected' : '' ?>><?= esc($status['Candidate_Joined_Status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a class="btn btn-light" href="/job_fair_results.php">Reset</a>
        </div>
    </div>
</form>

<?php
$baseParams = $_GET;
unset($baseParams['page'], $baseParams['candidate_call_history']);
render_pagination($page, $totalPages, $totalRecords, $perPage, '/job_fair_results.php', $baseParams, 'Job fair result pagination');
?>

<div class="card table-card mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th rowspan="2">Job Fair / Status</th>
                        <th rowspan="2">Candidate</th>
                        <th rowspan="2">Employer</th>
                        <th rowspan="2">Job</th>
                        <th rowspan="2">Days since Job Fair Date</th>
                        <th colspan="3" class="text-center">Offer Letter</th>
                        <th rowspan="2">Candidate Joined Status</th>
                        <th rowspan="2">Manage</th>
                    </tr>
                    <tr>
                        <th>Generated</th>
                        <th>Verified</th>
                        <th>Receipt Confirmed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="10"><div class="empty-state"><i class="bi bi-search"></i>No results found for the selected filters.</div></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $daySinceJobFair = null;
                        if (!empty($row['Job_Fair_Date'])) {
                            $jobFairDate = new DateTime($row['Job_Fair_Date']);
                            $today = new DateTime();
                            $daySinceJobFair = (int) $jobFairDate->diff($today)->format('%a');
                        }
                        $selKey = strtolower(str_replace(' ', '', (string) ($row['Selection_Status'] ?? '')));
                        $isShortlistOrOnhold = in_array($selKey, ['shortlisted', 'onhold'], true);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= esc($row['Job_Fair_No'] ?: 'N/A') ?></div>
                                <div class="mt-1"><?= render_status_chip($row['Selection_Status']) ?></div>
                                <?php if ($isShortlistOrOnhold && !empty($row['Shortlist_Candidate_Status'])): ?>
                                    <div class="mt-1"><?= render_status_chip($row['Shortlist_Candidate_Status'], 'Final:') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= esc($row['DWMS_ID'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Candidate_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td>
                                <div><?= esc($row['Employer_ID'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Employer_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td>
                                <div><?= esc($row['Job_Id'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Job_Title_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td><?= $daySinceJobFair !== null ? $daySinceJobFair : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td><?= render_status_chip($row['Offer_Letter_Generated']) ?></td>
                            <td><?= render_status_chip($row['Link_to_Offer_letter_verified']) ?></td>
                            <td><?= render_status_chip($row['Confirm_Offer_Letter_Receipt_by_Candidate']) ?></td>
                            <td><?= render_status_chip($row['Candidate_Joined_Status']) ?></td>
                            <td>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>&return_query=<?= urlencode($currentQueryString) ?>"
                                    aria-label="Manage candidate"
                                >
                                    ✏️
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/job_fair_results.php', $baseParams, 'Job fair result pagination'); ?>

<?php render_footer(); ?>
