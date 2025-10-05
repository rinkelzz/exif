<?php

declare(strict_types=1);

session_start();

$config = require __DIR__ . '/config.php';

$errors = [];
$successMessage = '';
$metadataHtml = '';
$metadataText = '';
$thumbnailDataUri = '';
$thumbnailForEmail = null;
$thumbnailMime = 'image/jpeg';

if (!isset($_SESSION['captcha_question'], $_SESSION['captcha_answer'])) {
    regenerateCaptcha();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $captchaInput = trim($_POST['captcha'] ?? '');
    if ($captchaInput === '' || !hash_equals((string)($_SESSION['captcha_answer'] ?? ''), $captchaInput)) {
        $errors[] = 'Das Captcha wurde nicht korrekt gelöst.';
    }

    $recipientEmail = trim($_POST['recipient_email'] ?? '');
    $sendEmail = isset($_POST['send_email']);

    if ($sendEmail && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte geben Sie eine gültige Empfänger-Adresse ein.';
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Bitte wählen Sie ein Bild zum Hochladen aus.';
    }

    $uploadedFilePath = null;
    $uploadedMime = null;

    if (empty($errors) && isset($_FILES['image'])) {
        $tmpPath = $_FILES['image']['tmp_name'];
        [$isValidImage, $uploadedMime] = determineImageMimeType($tmpPath);
        if (!$isValidImage) {
            $errors[] = 'Die hochgeladene Datei ist kein gültiges Bild.';
        } else {
            $uploadedFilePath = $tmpPath;
        }
    }

    if (empty($errors) && $uploadedFilePath !== null) {
        if (!function_exists('exif_read_data')) {
            $errors[] = 'Die PHP-EXIF-Erweiterung ist nicht verfügbar. Metadaten können nicht gelesen werden.';
        } else {
            $metadata = @exif_read_data($uploadedFilePath, null, true) ?: [];
            if (empty($metadata)) {
                $errors[] = 'Es konnten keine Metadaten aus dem Bild gelesen werden.';
            } else {
                [$metadataHtml, $metadataText] = formatMetadata($metadata);
            }
        }

        [$thumbnailDataUri, $thumbnailForEmail, $thumbnailMime] = generateThumbnail($uploadedFilePath, $uploadedMime);

        if ($sendEmail && empty($errors)) {
            if (sendMetadataEmail($recipientEmail, $metadataText, $thumbnailForEmail, $thumbnailMime, $config)) {
                $successMessage = 'Die Metadaten wurden per E-Mail versendet.';
            } else {
                $errors[] = 'Die E-Mail konnte nicht versendet werden.';
            }
        }
    }

    regenerateCaptcha();
}

function regenerateCaptcha(): void
{
    $first = random_int(2, 9);
    $second = random_int(1, 9);
    $_SESSION['captcha_question'] = sprintf('%d + %d = ?', $first, $second);
    $_SESSION['captcha_answer'] = (string)($first + $second);
}

function formatMetadata(array $metadata): array
{
    $html = '';
    $textLines = [];

    foreach ($metadata as $section => $data) {
        if (!is_array($data)) {
            continue;
        }

        $html .= '<h3>' . htmlspecialchars((string)$section, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        $html .= '<table class="metadata"><tbody>';
        $textLines[] = '[' . $section . ']';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $valueString = (string)$value;
            $html .= '<tr><th>' . htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
            $html .= '<td>' . htmlspecialchars($valueString, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>';
            $textLines[] = sprintf('%s: %s', $key, $valueString);
        }

        $html .= '</tbody></table>';
        $textLines[] = '';
    }

    return [$html, implode("\n", $textLines)];
}

function generateThumbnail(string $filePath, ?string $mime): array
{
    $thumbMime = 'image/jpeg';
    $width = $height = 0;
    $thumbnailData = function_exists('exif_thumbnail') ? @exif_thumbnail($filePath, $width, $height, $type) : false;
    if ($thumbnailData !== false && $width > 0 && $height > 0) {
        $imageType = $type ?? IMAGETYPE_JPEG;
        if (function_exists('image_type_to_mime_type')) {
            $thumbMime = image_type_to_mime_type($imageType);
        }
        return ['data:' . $thumbMime . ';base64,' . base64_encode($thumbnailData), $thumbnailData, $thumbMime];
    }

    if (!extension_loaded('gd') || !gdThumbnailSupportAvailable()) {
        return ['', null, $thumbMime];
    }

    $imageData = file_get_contents($filePath);
    if ($imageData === false) {
        return ['', null, $thumbMime];
    }

    $source = @imagecreatefromstring($imageData);
    if ($source === false) {
        return ['', null, $thumbMime];
    }

    $originalWidth = imagesx($source);
    $originalHeight = imagesy($source);

    if ($originalWidth === 0 || $originalHeight === 0) {
        imagedestroy($source);
        return ['', null, $thumbMime];
    }

    $targetWidth = 200;
    $scale = $targetWidth / $originalWidth;
    $targetHeight = (int)max(1, round($originalHeight * $scale));

    $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($thumbnail === false) {
        imagedestroy($source);
        return ['', null, $thumbMime];
    }

    if (!imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $originalWidth, $originalHeight)) {
        imagedestroy($source);
        imagedestroy($thumbnail);
        return ['', null, $thumbMime];
    }

    ob_start();
    if (!imagejpeg($thumbnail, null, 85)) {
        ob_end_clean();
        imagedestroy($source);
        imagedestroy($thumbnail);
        return ['', null, $thumbMime];
    }
    $generated = ob_get_clean();

    imagedestroy($source);
    imagedestroy($thumbnail);

    if ($generated === false) {
        return ['', null, $thumbMime];
    }

    $dataUri = 'data:' . $thumbMime . ';base64,' . base64_encode($generated);

    return [$dataUri, $generated, $thumbMime];
}

function gdThumbnailSupportAvailable(): bool
{
    $requiredFunctions = [
        'imagecreatefromstring',
        'imagecreatetruecolor',
        'imagecopyresampled',
        'imagejpeg',
    ];

    foreach ($requiredFunctions as $function) {
        if (!function_exists($function)) {
            return false;
        }
    }

    return true;
}

function determineImageMimeType(string $filePath): array
{
    $imageInfo = @getimagesize($filePath);
    if ($imageInfo !== false && isset($imageInfo['mime']) && strpos((string)$imageInfo['mime'], 'image/') === 0) {
        return [true, (string)$imageInfo['mime']];
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath) ?: null;
        if ($mime !== null && strpos($mime, 'image/') === 0) {
            return [true, $mime];
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($filePath);
        if ($mime !== false && strpos($mime, 'image/') === 0) {
            return [true, $mime];
        }
    }

    return [false, null];
}

function sendMetadataEmail(string $recipient, string $metadataText, ?string $thumbnail, string $thumbMime, array $config): bool
{
    $boundary = 'boundary_' . md5((string)microtime(true));

    $headers = [];
    $fromName = trim((string)($config['sender_name'] ?? ''));
    $fromEmail = trim((string)($config['sender_email'] ?? ''));

    if ($fromEmail === '') {
        return false;
    }

    $headers[] = 'From: ' . ($fromName !== '' ? sprintf('"%s" <%s>', addcslashes($fromName, '"'), $fromEmail) : $fromEmail);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $subject = (string)($config['mail_subject'] ?? 'Metadaten des Bildes');

    $body = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= ($metadataText !== '' ? $metadataText : 'Es wurden keine Metadaten gefunden.') . "\r\n\r\n";

    if ($thumbnail !== null) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $thumbMime . '; name="thumbnail.jpg"' . "\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"thumbnail.jpg\"\r\n\r\n";
        $body .= chunk_split(base64_encode($thumbnail)) . "\r\n";
    }

    $body .= '--' . $boundary . '--';

    return mail($recipient, $subject, $body, implode("\r\n", $headers));
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>EXIF Metadaten auslesen</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem auto;
            max-width: 900px;
            line-height: 1.5;
        }

        form {
            margin-bottom: 2rem;
            padding: 1rem;
            border: 1px solid #ccc;
            border-radius: 8px;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        input[type="file"],
        input[type="email"],
        input[type="text"] {
            width: 100%;
            padding: 0.5rem;
            margin-bottom: 1rem;
        }

        .messages {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .messages.error {
            background: #ffe5e5;
            border: 1px solid #f5aaaa;
        }

        .messages.success {
            background: #e5ffe9;
            border: 1px solid #aaf5b6;
        }

        table.metadata {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }

        table.metadata th,
        table.metadata td {
            border: 1px solid #ddd;
            padding: 0.5rem;
        }

        table.metadata th {
            background: #f6f6f6;
            width: 30%;
            text-align: left;
        }

        .thumbnail-preview img {
            max-width: 200px;
            height: auto;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <h1>Metadaten eines Bildes auslesen</h1>

    <?php if (!empty($errors)): ?>
        <div class="messages error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="messages success">
            <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label for="image">Bild auswählen</label>
        <input type="file" name="image" id="image" accept="image/*" required>

        <label for="recipient_email">Empfänger E-Mail (optional)</label>
        <input type="email" name="recipient_email" id="recipient_email" placeholder="person@example.com" value="<?= htmlspecialchars($_POST['recipient_email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">

        <label>
            <input type="checkbox" name="send_email" value="1" <?= isset($_POST['send_email']) ? 'checked' : ''; ?>>
            Metadaten per E-Mail versenden
        </label>

        <label for="captcha">Captcha: <?= htmlspecialchars($_SESSION['captcha_question'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></label>
        <input type="text" name="captcha" id="captcha" required>

        <button type="submit">Hochladen</button>
    </form>

    <?php if ($metadataHtml !== ''): ?>
        <section>
            <h2>Erkannte Metadaten</h2>
            <?= $metadataHtml; ?>
        </section>
    <?php endif; ?>

    <?php if ($thumbnailDataUri !== ''): ?>
        <section class="thumbnail-preview">
            <h2>Thumbnail</h2>
            <img src="<?= htmlspecialchars($thumbnailDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="Thumbnail des hochgeladenen Bildes">
        </section>
    <?php endif; ?>
</body>
</html>
