<?php
/**
 * EcoLink - donation media (photos/videos) + access helpers.
 */
const MEDIA_MAX_IMAGES = 6;
const MEDIA_MAX_VIDEOS = 2;
const MEDIA_IMAGE_MAX_BYTES = 8 * 1024 * 1024;   // 8 MB each
const MEDIA_VIDEO_MAX_BYTES = 40 * 1024 * 1024;  // 40 MB each (also check php.ini upload_max_filesize / post_max_size)

/** Save every valid file from <input type="file" name="media[]" multiple>. Returns [saved, skipped messages]. */
function saveDonationMedia(PDO $db, int $donationId, string $field = 'media'): array
{
    $saved = 0; $problems = [];
    if (empty($_FILES[$field]['name'])) return [0, []];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    $imgExt = ['jpg','jpeg','png','webp','gif'];
    $vidExt = ['mp4','webm','mov','m4v'];
    $names = (array)$_FILES[$field]['name'];
    $images = 0; $videos = 0;
    $firstImage = null;

    foreach ($names as $i => $orig) {
        if ($orig === '' || $orig === null) continue;
        $err  = (array)$_FILES[$field]['error'];
        $tmp  = (array)$_FILES[$field]['tmp_name'];
        $size = (array)$_FILES[$field]['size'];
        if ($err[$i] !== UPLOAD_ERR_OK) {
            $problems[] = "$orig could not be uploaded (file too large for the server?).";
            continue;
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $mime = function_exists('mime_content_type') ? (string)mime_content_type($tmp[$i]) : '';
        $type = null;
        if (in_array($ext, $imgExt, true) && ($mime === '' || str_starts_with($mime, 'image/'))) $type = 'image';
        elseif (in_array($ext, $vidExt, true) && ($mime === '' || str_starts_with($mime, 'video/'))) $type = 'video';
        if (!$type) { $problems[] = "$orig: only photos (jpg, png, webp) and videos (mp4, webm, mov) are allowed."; continue; }

        if ($type === 'image') {
            if ($images >= MEDIA_MAX_IMAGES) { $problems[] = "$orig skipped (max " . MEDIA_MAX_IMAGES . " photos)."; continue; }
            if ($size[$i] > MEDIA_IMAGE_MAX_BYTES) { $problems[] = "$orig is bigger than 8 MB."; continue; }
        } else {
            if ($videos >= MEDIA_MAX_VIDEOS) { $problems[] = "$orig skipped (max " . MEDIA_MAX_VIDEOS . " videos)."; continue; }
            if ($size[$i] > MEDIA_VIDEO_MAX_BYTES) { $problems[] = "$orig is bigger than 40 MB."; continue; }
        }
        $fname = 'donation_' . $donationId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp[$i], UPLOAD_DIR . $fname)) { $problems[] = "$orig could not be saved."; continue; }
        $db->prepare("INSERT INTO donation_media (donation_id, file_path, media_type) VALUES (?, ?, ?)")
           ->execute([$donationId, $fname, $type]);
        if ($type === 'image') { $images++; $firstImage = $firstImage ?? $fname; } else { $videos++; }
        $saved++;
    }
    if ($firstImage) {
        $db->prepare("UPDATE donations SET image_path = ? WHERE donation_id = ? AND image_path IS NULL")->execute([$firstImage, $donationId]);
    }
    return [$saved, $problems];
}

/** All media for a donation (falls back to legacy donations.image_path). */
function donationMedia(PDO $db, int $donationId, ?string $legacyImage = null): array
{
    $st = $db->prepare("SELECT file_path, media_type FROM donation_media WHERE donation_id = ? ORDER BY media_type, media_id");
    $st->execute([$donationId]);
    $rows = $st->fetchAll();
    if (!$rows && $legacyImage) $rows[] = ['file_path' => $legacyImage, 'media_type' => 'image'];
    return $rows;
}

/** Link to the shared donation detail page. */
function donationUrl(int $donationId): string
{
    return BASE_URL . '/donation_view.php?id=' . $donationId;
}

/** Small clickable thumbnail (first photo) or an emoji placeholder. Wraps in a link to the detail page. */
function donationThumb(array $d, string $icon = '📦', int $size = 84): string
{
    $url = donationUrl((int)$d['donation_id']);
    $style = "width:{$size}px;height:{$size}px;border-radius:10px;object-fit:cover;display:flex;align-items:center;justify-content:center;background:var(--mint);font-size:" . (int)($size / 2.4) . "px;flex-shrink:0;";
    if (!empty($d['image_path'])) {
        return '<a href="' . $url . '" title="Tap to see details"><img src="' . UPLOAD_URL . e($d['image_path']) . '" alt="" style="' . $style . '"></a>';
    }
    return '<a href="' . $url . '" title="Tap to see details" style="' . $style . 'text-decoration:none;">' . $icon . '</a>';
}
