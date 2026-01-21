<?php
/**
 * Template Name: IP Camera Proxy
 * 
 * This template proxies IP camera JPEG snapshots and RTSP streams.
 * It outputs image data directly to the browser, bypassing WordPress rendering.
 * 
 * Usage:
 * - Create a page and assign this template
 * - Access via: /page-slug/?tab=1 (port 8001) or /page-slug/?tab=2 (port 8003)
 * - Or use shortcode: [cryo_ipcam tab="1"]
 */

// Determine port based on tab parameter
if (isset($_GET['tab'])) {
    if ($_GET['tab'] == '1') {
        $port = '8001';
    } else {
        $port = '8003';
    }
} else {
    $port = '8003'; // Default port
}

// Check if RTSP stream is requested
$rtsp_mode = isset($_GET['rtsp']) && $_GET['rtsp'] === '1';

if ($rtsp_mode) {
    // RTSP stream handling (requires additional server-side processing)
    // For now, redirect to snapshot mode
    // Full RTSP support would require:
    // - FFmpeg to convert RTSP to HLS/WebRTC
    // - Or use a service like Wowza/Media Server
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>RTSP Stream</title></head><body>';
    echo '<p>RTSP streaming requires server-side processing. Please use snapshot mode.</p>';
    echo '<p><a href="?' . http_build_query(array_merge($_GET, ['rtsp' => '0'])) . '">View Snapshot</a></p>';
    echo '</body></html>';
    exit;
}

// Build camera URL
// Note: cam%23cttc is URL-encoded for cam#cttc
$url = "http://root:cam%23cttc@202.64.221.98:$port/cgi-bin/video.jpg";

// Set appropriate headers for image
$imginfo = @getimagesize($url);
if ($imginfo && isset($imginfo['mime'])) {
    header('Content-Type: ' . $imginfo['mime']);
    // Cache control - allow short-term caching for snapshots
    header('Cache-Control: public, max-age=5');
} else {
    // Fallback to JPEG if getimagesize fails
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=5');
}

// Stream the image directly to browser
@readfile($url);
exit;
