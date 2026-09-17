<?php
// config/cloudinary.php
function uploadToCloudinary(string $tmpPath, string $folder = 'falcon'): array {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey    = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    if (!$cloudName || !$apiKey || !$apiSecret) {
        throw new RuntimeException('Cloudinary credentials not configured.');
    }

    $timestamp = time();
    $params    = "folder={$folder}&timestamp={$timestamp}{$apiSecret}";
    $signature = sha1($params);

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($tmpPath),
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'folder'    => $folder,
            'signature' => $signature,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new RuntimeException('Cloudinary upload failed: ' . $response);
    }

    return json_decode($response, true);
}

function deleteFromCloudinary(string $publicId): void {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey    = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    $timestamp = time();
    $signature = sha1("public_id={$publicId}&timestamp={$timestamp}{$apiSecret}");

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'public_id' => $publicId,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}