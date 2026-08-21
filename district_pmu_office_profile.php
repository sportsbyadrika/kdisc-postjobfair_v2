<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_pmu_user();
district_pmu_bootstrap();

$user       = current_user();
$userId     = (int) $user['id'];
$districts  = district_pmu_user_districts($user);
$district   = district_pmu_current_district($user);

$flashMessage = null;
$flashType    = 'success';

$profile = $district !== '' ? district_pmu_get_profile_by_district($district) : null;

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    // Guard: the district must be one the user is actually assigned to,
    // even if the POST value was tampered with.
    $postDistrict = trim((string) ($_POST['district'] ?? ''));
    if ($postDistrict !== '' && in_array($postDistrict, $districts, true)) {
        $district = $postDistrict;
        $profile  = district_pmu_get_profile_by_district($district);
    }
    $officeName    = trim((string) ($_POST['office_name']    ?? ''));
    $address       = trim((string) ($_POST['address']        ?? ''));
    $pincode       = trim((string) ($_POST['pincode']        ?? ''));
    $spocName      = trim((string) ($_POST['spoc_name']      ?? ''));
    $spocContact   = trim((string) ($_POST['spoc_contact']   ?? ''));
    $latRaw        = trim((string) ($_POST['latitude']       ?? ''));
    $lngRaw        = trim((string) ($_POST['longitude']      ?? ''));
    $latitude  = ($latRaw !== '' && is_numeric($latRaw)) ? (float) $latRaw : null;
    $longitude = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float) $lngRaw : null;

    // Photo handling — save under uploads/district_pmu/{district}/ so
    // artefacts survive user turnover (district is the stable owning
    // entity). Existing photo is overwritten on re-upload.
    $existingBuildingPath = $profile['building_photo_path'] ?? null;
    $existingRoomPath     = $profile['room_photo_path']     ?? null;
    $buildingPhotoPath    = $existingBuildingPath;
    $roomPhotoPath        = $existingRoomPath;

    $photoError = null;
    $handlePhoto = static function (string $formField, string $baseName, ?string &$destPathVar, string $district, ?string &$photoErrorRef): void {
        if (!isset($_FILES[$formField]) || $_FILES[$formField]['error'] === UPLOAD_ERR_NO_FILE) {
            return; // no new upload for this field
        }
        if ($_FILES[$formField]['error'] !== UPLOAD_ERR_OK) {
            $photoErrorRef = 'Upload failed for ' . $formField . ' (code ' . (int) $_FILES[$formField]['error'] . ')';
            return;
        }
        $size = (int) $_FILES[$formField]['size'];
        if ($size > 5 * 1024 * 1024) {
            $photoErrorRef = 'Photo ' . $formField . ' exceeds 5 MB.';
            return;
        }
        $mime = @mime_content_type($_FILES[$formField]['tmp_name']) ?: '';
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extMap[$mime])) {
            $photoErrorRef = 'Photo ' . $formField . ' must be JPEG, PNG or WebP.';
            return;
        }
        $dir = district_pmu_upload_dir($district);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $photoErrorRef = 'Could not create upload directory for district.';
            return;
        }
        $filename = $baseName . '.' . $extMap[$mime];
        $target   = $dir . '/' . $filename;
        if (!move_uploaded_file($_FILES[$formField]['tmp_name'], $target)) {
            $photoErrorRef = 'Could not save ' . $formField . '.';
            return;
        }
        @chmod($target, 0644);
        $destPathVar = district_pmu_upload_url($district, $filename);
    };

    if ($district === '') {
        $flashMessage = 'Your account has no district assigned. Ask an Administrator to set it before saving your Office Profile.';
        $flashType = 'danger';
    } else {
        $handlePhoto('building_photo', 'building', $buildingPhotoPath, $district, $photoError);
        if ($photoError === null) {
            $handlePhoto('room_photo', 'room', $roomPhotoPath, $district, $photoError);
        }

        if ($photoError !== null) {
            $flashMessage = $photoError;
            $flashType = 'danger';
        } else {
            $stmt = db()->prepare('INSERT INTO district_pmu_office_profile
                (district, office_name, address, pincode, spoc_name, spoc_contact, latitude, longitude, building_photo_path, room_photo_path, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    office_name         = VALUES(office_name),
                    address             = VALUES(address),
                    pincode             = VALUES(pincode),
                    spoc_name           = VALUES(spoc_name),
                    spoc_contact        = VALUES(spoc_contact),
                    latitude            = VALUES(latitude),
                    longitude           = VALUES(longitude),
                    building_photo_path = VALUES(building_photo_path),
                    room_photo_path     = VALUES(room_photo_path),
                    updated_by          = VALUES(updated_by),
                    updated_at          = NOW()');
            $stmt->execute([
                $district,
                $officeName   === '' ? null : $officeName,
                $address      === '' ? null : $address,
                $pincode      === '' ? null : $pincode,
                $spocName     === '' ? null : $spocName,
                $spocContact  === '' ? null : $spocContact,
                $latitude,
                $longitude,
                $buildingPhotoPath,
                $roomPhotoPath,
                $userId,
            ]);
            $flashMessage = 'Office profile saved for ' . $district . '.';
            $profile = district_pmu_get_profile_by_district($district); // re-read for the form
        }
    }
}

// Defaults for the form — profile row if it exists, otherwise blanks.
$officeName  = (string) ($profile['office_name']  ?? '');
$address     = (string) ($profile['address']      ?? '');
$pincode     = (string) ($profile['pincode']      ?? '');
$spocName    = (string) ($profile['spoc_name']    ?? '');
$spocContact = (string) ($profile['spoc_contact'] ?? '');
$latitude    = ($profile['latitude']  ?? null) !== null ? (string) $profile['latitude']  : '';
$longitude   = ($profile['longitude'] ?? null) !== null ? (string) $profile['longitude'] : '';
$buildingPhoto = (string) ($profile['building_photo_path'] ?? '');
$roomPhoto     = (string) ($profile['room_photo_path']     ?? '');
$updatedAt   = (string) ($profile['updated_at'] ?? '');

render_header('District PMU · Office Profile');
render_page_header('District PMU · Office Profile', [
    'icon'     => 'bi-building-check',
    'subtitle' => 'Details of the PMU office in your district — used centrally for coordination and reporting.',
    'actions'  => district_pmu_render_district_switcher($user, $district)
        . '<a class="btn btn-light ms-2" href="/district_pmu_dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<?php if ($districts === []): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        Your user account has no assigned district. Ask an Administrator to set one (or more) on your user record before saving an Office Profile.
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card">
    <input type="hidden" name="action" value="save">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">District <span class="text-danger">*</span></label>
                <input type="text" class="form-control" value="<?= esc($district) ?>" readonly>
                <input type="hidden" name="district" value="<?= esc($district) ?>">
                <?php if (count($districts) > 1): ?>
                    <div class="small text-muted mt-1">Use the District switcher at the top-right to edit a different district.</div>
                <?php else: ?>
                    <div class="small text-muted mt-1">Assigned by your Administrator.</div>
                <?php endif; ?>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="office_name">Office name</label>
                <input type="text" class="form-control" id="office_name" name="office_name" value="<?= esc($officeName) ?>" placeholder="e.g. District PMU, Kollam">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="pincode">Pincode</label>
                <input type="text" class="form-control" id="pincode" name="pincode" value="<?= esc($pincode) ?>" pattern="\d{6}" maxlength="6" inputmode="numeric">
            </div>
            <div class="col-md-2">
                <label class="form-label">Last updated</label>
                <input type="text" class="form-control" value="<?= esc($updatedAt !== '' ? $updatedAt : '—') ?>" readonly>
            </div>
            <div class="col-md-12">
                <label class="form-label" for="address">Address</label>
                <textarea class="form-control" id="address" name="address" rows="2" placeholder="Full postal address"><?= esc($address) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="spoc_name">Existing SPOC Name</label>
                <input type="text" class="form-control" id="spoc_name" name="spoc_name" value="<?= esc($spocName) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="spoc_contact">Existing SPOC contact (Mobile Number)</label>
                <input type="text" class="form-control" id="spoc_contact" name="spoc_contact" value="<?= esc($spocContact) ?>" placeholder="Mobile number">
            </div>

            <div class="col-md-6">
                <label class="form-label" for="building_photo">Photo of the building</label>
                <input type="file" class="form-control" id="building_photo" name="building_photo" accept="image/jpeg,image/png,image/webp">
                <div class="small text-muted mt-1">JPEG, PNG or WebP, up to 5 MB. Uploading replaces the existing photo.</div>
                <?php if ($buildingPhoto !== ''): ?>
                    <div class="mt-2">
                        <a href="<?= esc($buildingPhoto) ?>" target="_blank" rel="noopener">
                            <img src="<?= esc($buildingPhoto) ?>" alt="Building photo" style="max-width:200px; max-height:120px; border-radius:.25rem;">
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="room_photo">Photo of the room</label>
                <input type="file" class="form-control" id="room_photo" name="room_photo" accept="image/jpeg,image/png,image/webp">
                <div class="small text-muted mt-1">JPEG, PNG or WebP, up to 5 MB. Uploading replaces the existing photo.</div>
                <?php if ($roomPhoto !== ''): ?>
                    <div class="mt-2">
                        <a href="<?= esc($roomPhoto) ?>" target="_blank" rel="noopener">
                            <img src="<?= esc($roomPhoto) ?>" alt="Room photo" style="max-width:200px; max-height:120px; border-radius:.25rem;">
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="latitude">Latitude</label>
                <input type="text" class="form-control" id="latitude" name="latitude" value="<?= esc($latitude) ?>" placeholder="e.g. 8.5241">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="longitude">Longitude</label>
                <input type="text" class="form-control" id="longitude" name="longitude" value="<?= esc($longitude) ?>" placeholder="e.g. 76.9366">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="w-100">
                    <div class="small text-muted mb-1"><i class="bi bi-info-circle me-1"></i>Click a point on the map below to fill both fields. Search box on the map jumps you closer.</div>
                    <div class="d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="mapSearch" placeholder="Search a place…">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="mapSearchBtn"><i class="bi bi-search"></i></button>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div id="officeMap" style="height:360px; border:1px solid var(--bs-border-color); border-radius:.375rem;"></div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="/district_pmu_office_profile.php">Reset</a>
        <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save profile</button>
    </div>
</form>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function () {
    // Initial view — the existing profile point if we have one, otherwise
    // Kerala's rough centre so the operator can pan to their district.
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const startLat = parseFloat(latInput.value)  || 10.8505;
    const startLng = parseFloat(lngInput.value)  || 76.2711;
    const hasPoint = !!(parseFloat(latInput.value) && parseFloat(lngInput.value));

    const map = L.map('officeMap').setView([startLat, startLng], hasPoint ? 15 : 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    let marker = hasPoint ? L.marker([startLat, startLng], {draggable: true}).addTo(map) : null;
    const setPoint = (lat, lng) => {
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], {draggable: true}).addTo(map);
            marker.on('dragend', (ev) => {
                const p = ev.target.getLatLng();
                setPoint(p.lat, p.lng);
            });
        }
    };
    if (marker) {
        marker.on('dragend', (ev) => {
            const p = ev.target.getLatLng();
            setPoint(p.lat, p.lng);
        });
    }
    map.on('click', (ev) => { setPoint(ev.latlng.lat, ev.latlng.lng); });

    // Manual entry keeps the marker in sync.
    const syncFromInputs = () => {
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            map.setView([lat, lng], Math.max(map.getZoom(), 13));
            setPoint(lat, lng);
        }
    };
    latInput.addEventListener('change', syncFromInputs);
    lngInput.addEventListener('change', syncFromInputs);

    // Simple place search via Nominatim. Rate-limited to one request per
    // click by the button; do not spam. Only used for finding an initial
    // point — the actual coordinates come from the click / drag / typed
    // input, not from the search result.
    const search = document.getElementById('mapSearch');
    const searchBtn = document.getElementById('mapSearchBtn');
    const runSearch = () => {
        const q = search.value.trim();
        if (q === '') return;
        const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
        fetch(url, {headers: {'Accept': 'application/json'}})
            .then((r) => r.ok ? r.json() : [])
            .then((rows) => {
                if (!Array.isArray(rows) || rows.length === 0) { alert('No match on the map.'); return; }
                const p = rows[0];
                const lat = parseFloat(p.lat), lng = parseFloat(p.lon);
                map.setView([lat, lng], 14);
                setPoint(lat, lng);
            })
            .catch(() => alert('Could not reach the map search service.'));
    };
    searchBtn.addEventListener('click', runSearch);
    search.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); runSearch(); } });
})();
</script>

<?php render_footer(); ?>
