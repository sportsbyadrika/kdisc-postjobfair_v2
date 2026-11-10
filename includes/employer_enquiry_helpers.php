<?php
/**
 * Employer Enquiry · schema bootstrap + shared helpers.
 *
 * Owns two tables:
 *   employer_enquiry         — one row per submission.
 *   employer_enquiry_setting — key/value for configurable text
 *                              (the Malayalam header, the thank-you
 *                              message, etc.) editable later from
 *                              the admin module.
 *
 * Idempotent bootstrap, same pattern as the other modules: CREATE
 * TABLE IF NOT EXISTS, column-add via SHOW COLUMNS + ALTER TABLE
 * guarded by try/catch. No migrations/ directory.
 *
 * The public page (/employers/index.php) and the internal admin
 * module (Phase 3) both pull from here.
 */

require_once __DIR__ . '/db.php';

const EMPLOYER_ENQUIRY_DEFAULT_HEADER =
    'കേരള നോളജ് ഇക്കോണമി മിഷൻ (KKEM) - DWMS വഴി ലഭ്യമാക്കുന്ന വിവിധ സേവനങ്ങൾ വേണ്ടി ' .
    'നിങ്ങളുടെ സ്ഥാപനത്തിലേക്ക് ആവശ്യമായ ഉദ്യോഗാർത്ഥികളെ ലഭിക്കാൻ, പരിശീലനം നൽകുക ' .
    'തുടങ്ങിയ കാര്യങ്ങൾ ലഭ്യമാക്കുന്നതിനായി, നിങ്ങളുടെ കമ്പനിയെ DWMS പോർട്ടലിൽ Onboard ' .
    'ചെയ്യാൻ താഴെ കാണുന്ന ലിങ്കിൽ വിവരങ്ങൾ നൽകുക.';

const EMPLOYER_ENQUIRY_DEFAULT_THANKS =
    'വിവരങ്ങൾ നൽകിയതിന് നന്ദി. KKEM ടീം ഉടൻ നിങ്ങളെ ബന്ധപ്പെടുന്നതാണ്.';

const EMPLOYER_ENQUIRY_COMPANY_TYPES = [
    'Government', 'Public Sector', 'Private', 'Startup', 'MSME',
    'NGO', 'Educational Institution', 'Cooperative Society', 'Others',
];

/**
 * ISO 3166-1 country list, India removed — the public form stores
 * "India" on its own path (with a state/district cascade), so for
 * the Outside-India branch the operator picks a specific country
 * from this list and that name is saved in the `country` column.
 * Short display names, in alphabetical order.
 */
const EMPLOYER_ENQUIRY_COUNTRIES_EXCEPT_INDIA = [
    'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola',
    'Antigua and Barbuda', 'Argentina', 'Armenia', 'Australia',
    'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh',
    'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan',
    'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil',
    'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cabo Verde',
    'Cambodia', 'Cameroon', 'Canada', 'Central African Republic',
    'Chad', 'Chile', 'China', 'Colombia', 'Comoros',
    'Congo (Brazzaville)', 'Congo (Kinshasa)', 'Costa Rica',
    "Côte d'Ivoire", 'Croatia', 'Cuba', 'Cyprus', 'Czech Republic',
    'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic',
    'Ecuador', 'Egypt', 'El Salvador', 'Equatorial Guinea',
    'Eritrea', 'Estonia', 'Eswatini', 'Ethiopia', 'Fiji', 'Finland',
    'France', 'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana',
    'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
    'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland',
    'Indonesia', 'Iran', 'Iraq', 'Ireland', 'Israel', 'Italy',
    'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati',
    'Korea (North)', 'Korea (South)', 'Kuwait', 'Kyrgyzstan',
    'Laos', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya',
    'Liechtenstein', 'Lithuania', 'Luxembourg', 'Madagascar',
    'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta',
    'Marshall Islands', 'Mauritania', 'Mauritius', 'Mexico',
    'Micronesia', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro',
    'Morocco', 'Mozambique', 'Myanmar (Burma)', 'Namibia', 'Nauru',
    'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua', 'Niger',
    'Nigeria', 'North Macedonia', 'Norway', 'Oman', 'Pakistan',
    'Palau', 'Palestine', 'Panama', 'Papua New Guinea', 'Paraguay',
    'Peru', 'Philippines', 'Poland', 'Portugal', 'Qatar', 'Romania',
    'Russia', 'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia',
    'Saint Vincent and the Grenadines', 'Samoa', 'San Marino',
    'Sao Tome and Principe', 'Saudi Arabia', 'Senegal', 'Serbia',
    'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia',
    'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa',
    'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname',
    'Sweden', 'Switzerland', 'Syria', 'Taiwan', 'Tajikistan',
    'Tanzania', 'Thailand', 'Timor-Leste', 'Togo', 'Tonga',
    'Trinidad and Tobago', 'Tunisia', 'Turkey', 'Turkmenistan',
    'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates',
    'United Kingdom', 'United States', 'Uruguay', 'Uzbekistan',
    'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam', 'Yemen',
    'Zambia', 'Zimbabwe',
];

/** Case-insensitive check: is this a recognised non-India country? */
function employer_enquiry_is_valid_foreign_country(string $name): bool
{
    $needle = mb_strtolower(trim($name));
    if ($needle === '') return false;
    foreach (EMPLOYER_ENQUIRY_COUNTRIES_EXCEPT_INDIA as $c) {
        if (mb_strtolower($c) === $needle) return true;
    }
    return false;
}

function employer_enquiry_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();
    try {
        $db->query("CREATE TABLE IF NOT EXISTS employer_enquiry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reference_number VARCHAR(30) NOT NULL,
            company_name VARCHAR(255) NOT NULL,
            hr_email VARCHAR(255) NOT NULL,
            hr_phone VARCHAR(50) NOT NULL,
            contact_person_name VARCHAR(255) NOT NULL,
            website VARCHAR(500) NULL,
            address TEXT NOT NULL,
            country VARCHAR(100) NOT NULL DEFAULT 'India',
            state VARCHAR(120) NULL,
            district VARCHAR(160) NULL,
            company_type VARCHAR(80) NOT NULL,
            pan_number VARCHAR(40) NULL,
            tin_cin_number VARCHAR(50) NULL,
            interested_dwms TINYINT(1) NOT NULL DEFAULT 0,
            vacancies_posts INT NULL,
            vacancies_open_positions INT NULL,
            internship_available TINYINT(1) NOT NULL DEFAULT 0,
            internship_with_stipend TINYINT(1) NOT NULL DEFAULT 0,
            internship_open_positions INT NULL,
            submitter_ip VARCHAR(45) NULL,
            submitter_ua VARCHAR(500) NULL,
            status ENUM('new','contacted','onboarded','declined','spam') NOT NULL DEFAULT 'new',
            notes TEXT NULL,
            processed_at DATETIME NULL,
            processed_by INT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_reference (reference_number),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_company (company_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Idempotent column add: postcode landed in the form after
        // the first cut of the schema was pushed. SHOW COLUMNS
        // short-circuits the ALTER on repeat runs so it's safe.
        $has = $db->query("SHOW COLUMNS FROM employer_enquiry LIKE 'postcode'")->fetchAll();
        if ($has === []) {
            try { $db->query("ALTER TABLE employer_enquiry ADD COLUMN postcode VARCHAR(20) NULL AFTER district"); }
            catch (Throwable $e) { /* ignore */ }
        }

        $db->query("CREATE TABLE IF NOT EXISTS employer_enquiry_setting (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value LONGTEXT NULL,
            updated_at DATETIME NULL,
            updated_by INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed the default Malayalam header + thank-you text. Only
        // written when the row doesn't exist, so admin edits in
        // Phase 3 are never clobbered on a subsequent bootstrap.
        $exists = $db->prepare('SELECT 1 FROM employer_enquiry_setting WHERE setting_key = ? LIMIT 1');
        $insert = $db->prepare('INSERT INTO employer_enquiry_setting (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())');
        foreach ([
            'header_text'  => EMPLOYER_ENQUIRY_DEFAULT_HEADER,
            'thanks_text'  => EMPLOYER_ENQUIRY_DEFAULT_THANKS,
            'contact_email' => '',
        ] as $k => $v) {
            $exists->execute([$k]);
            if (!$exists->fetchColumn()) $insert->execute([$k, $v]);
        }
    } catch (Throwable $e) {
        // CREATE refused — getters below fall back to defaults.
    }
}

/** Read one setting value, with a default fallback. */
function employer_enquiry_setting(string $key, string $default = ''): string
{
    try {
        $st = db()->prepare('SELECT setting_value FROM employer_enquiry_setting WHERE setting_key = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function employer_enquiry_setting_write(string $key, string $value, int $userId = 0): void
{
    $db = db();
    $db->prepare('INSERT INTO employer_enquiry_setting (setting_key, setting_value, updated_at, updated_by)
        VALUES (?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW(), updated_by = VALUES(updated_by)')
        ->execute([$key, $value, $userId ?: null]);
}

/**
 * Generate a reference number for a new submission. Shape:
 *   EE-YYYYMMDD-XXXX
 * where XXXX is uppercase alphanumeric. Tries up to 5 times in the
 * vanishingly unlikely event of a collision on the UNIQUE index.
 */
function employer_enquiry_make_reference(): string
{
    $date = date('Ymd');
    for ($i = 0; $i < 5; $i++) {
        $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $ref  = 'EE-' . $date . '-' . $rand;
        try {
            $st = db()->prepare('SELECT 1 FROM employer_enquiry WHERE reference_number = ? LIMIT 1');
            $st->execute([$ref]);
            if (!$st->fetchColumn()) return $ref;
        } catch (Throwable $e) { return $ref; }
    }
    // Deterministic fall-through — timestamp-tail keeps it unique.
    return 'EE-' . $date . '-' . strtoupper(substr(dechex((int) (microtime(true) * 1000)), -4));
}

/** Phone / PAN quick-shape validators. Both lenient — the KKEM team
 *  verifies the real thing, this just blocks obvious garbage. */
function employer_enquiry_valid_phone(string $v): bool
{
    $digits = preg_replace('/\D+/', '', $v);
    return strlen($digits) >= 7 && strlen($digits) <= 15;
}
function employer_enquiry_valid_pan(string $v): bool
{
    // Accepts blank (optional). Standard PAN shape: 5 letters, 4
    // digits, 1 letter — all uppercase.
    $v = strtoupper(trim($v));
    return $v === '' || (bool) preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $v);
}

/** Row → human-friendly display array for admin list + detail. */
function employer_enquiry_status_badge(string $status): array
{
    return match ($status) {
        'contacted'  => ['label' => 'Contacted',  'tone' => 'info'],
        'onboarded'  => ['label' => 'Onboarded',  'tone' => 'success'],
        'declined'   => ['label' => 'Declined',   'tone' => 'secondary'],
        'spam'       => ['label' => 'Spam',       'tone' => 'danger'],
        default      => ['label' => 'New',        'tone' => 'warning'],
    };
}
