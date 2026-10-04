<?php
/**
 * Upload gambar (menu, flyer, promo).
 * Validasi: ekstensi (jpg/jpeg/png/webp), MIME asli via finfo, getimagesize(), ukuran <= 2MB.
 * Nama file final diacak; ekstensi ditentukan dari MIME, bukan nama upload. SVG/PHP/JS/HTML ditolak.
 * Return [path_relatif|null, error|null].
 */
function save_image_upload(array $file, string $absBase, string $relPrefix): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Ukuran gambar terlalu besar (maks 2MB).' : 'Upload gambar gagal.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return [null, 'Ukuran gambar terlalu besar (maks 2MB).'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Upload gambar tidak valid.'];
    }
    $origExt = strtolower(pathinfo(isset($file['name']) ? (string) $file['name'] : '', PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!in_array($origExt, ['jpg', 'jpeg', 'png', 'webp'], true) || !isset($ext[$mime]) || @getimagesize($file['tmp_name']) === false) {
        return [null, 'Gambar harus berformat JPG, PNG, atau WebP.'];
    }
    $dir = rtrim($absBase, '/') . '/' . trim($relPrefix, '/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [null, 'Folder gambar tidak dapat dibuat.'];
    }
    $rel = trim($relPrefix, '/') . '/' . bin2hex(random_bytes(8)) . '.' . $ext[$mime];
    if (!move_uploaded_file($file['tmp_name'], rtrim($absBase, '/') . '/' . $rel)) {
        return [null, 'Gambar gagal disimpan.'];
    }
    return [$rel, null];
}

/** Foto menu (tetap di assets/images/menu/, kompatibel dengan data lama). */
function save_menu_image(array $file): array
{
    return save_image_upload($file, ROOT_PATH . '/assets/images', 'menu');
}

/** Flyer / promo -> assets/uploads/{flyers|promos}/. Return path relatif terhadap assets/. */
function save_upload_image(array $file, string $folder): array
{
    list($rel, $err) = save_image_upload($file, ROOT_PATH . '/assets/uploads', $folder);
    return [$rel ? 'uploads/' . $rel : null, $err];
}

/** Hapus gambar lama; hanya path berpola aman di dalam folder upload. */
function delete_menu_image(?string $name): void
{
    if ($name && preg_match('#^menu/[a-f0-9]{16}\.(jpg|png|webp)$#', $name)) {
        @unlink(ROOT_PATH . '/assets/images/' . $name);
    }
}

function delete_upload_image(?string $path): void
{
    if ($path && preg_match('#^uploads/(flyers|promos)/[a-f0-9]{16}\.(jpg|png|webp)$#', $path)) {
        @unlink(ROOT_PATH . '/assets/' . $path);
    }
}
