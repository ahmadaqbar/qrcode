<?php
/**
 * Upload foto menu. Return [nama_file|null, error|null].
 * Validasi: ukuran <= 2MB, tipe asli (finfo) jpeg/png/webp, benar-benar gambar (getimagesize).
 * Nama file diacak; ekstensi ditentukan dari tipe, bukan dari nama upload user.
 */
function save_menu_image(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Ukuran foto terlalu besar (maks 2MB).' : 'Upload foto gagal.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, 'Ukuran foto terlalu besar (maks 2MB).'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Upload foto tidak valid.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($ext[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return [null, 'Foto harus berformat JPG, PNG, atau WebP.'];
    }
    $dir = ROOT_PATH . '/assets/images/menu';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [null, 'Folder foto tidak dapat dibuat.'];
    }
    $name = 'menu/' . bin2hex(random_bytes(8)) . '.' . $ext[$mime];
    if (!move_uploaded_file($file['tmp_name'], ROOT_PATH . '/assets/images/' . $name)) {
        return [null, 'Foto gagal disimpan.'];
    }
    return [$name, null];
}

/** Hapus foto lama (hanya di dalam assets/images/menu/). */
function delete_menu_image(?string $name): void
{
    if ($name && preg_match('#^menu/[a-f0-9]{16}\.(jpg|png|webp)$#', $name)) {
        @unlink(ROOT_PATH . '/assets/images/' . $name);
    }
}
