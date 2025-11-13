<?php
/**
 * URL Helper Functions for Friendly URLs
 */

/**
 * Generate friendly URL
 */
function url($path = '', $params = []) {
    $baseUrl = rtrim(BASE_URL, '/');
    
    // Handle absolute URLs
    if (strpos($path, 'http') === 0) {
        return $path;
    }
    
    // Handle file paths (for assets, etc.)
    if (strpos($path, '.') !== false && !strpos($path, '/') === 0) {
        return $baseUrl . '/' . $path;
    }
    
    // Build friendly URL
    $url = $baseUrl;
    
    if (!empty($path)) {
        $url .= '/' . ltrim($path, '/');
    }
    
    // Add query parameters if any
    if (!empty($params)) {
        // Use http_build_query but ensure proper encoding
        // Note: http_build_query automatically encodes values, which is correct
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    
    return $url;
}

/**
 * Generate website management URL
 */
function websiteUrl($websiteId, $tab = 'files', $path = '', $action = '') {
    $urlPath = 'website/' . $websiteId;
    
    if (!empty($tab)) {
        $urlPath .= '/' . $tab;
    }
    
    if ($action === 'edit' && !empty($path)) {
        // For edit action, use edit:path format
        // Encode the path but keep slashes for path segments
        $pathSegments = explode('/', $path);
        $encodedPath = implode('/', array_map('rawurlencode', $pathSegments));
        $urlPath .= '/edit:' . $encodedPath;
    } elseif (!empty($path)) {
        // Add path as segments - encode each segment separately
        $pathSegments = explode('/', $path);
        foreach ($pathSegments as $segment) {
            if (!empty($segment)) {
                $urlPath .= '/' . rawurlencode($segment);
            }
        }
    }
    
    return url($urlPath);
}

/**
 * Generate admin URL
 */
function adminUrl($type = 'websites', $action = '', $id = '') {
    $url = url('admin/' . $type);
    
    if (!empty($action) && !empty($id)) {
        $url = url('admin/' . $type . '/' . $action . '/' . $id);
    }
    
    return $url;
}

/**
 * Generate backup download URL
 */
function backupUrl($backupId) {
    return url('backup/' . $backupId . '/download');
}

/**
 * Generate AJAX URL
 */
function ajaxUrl($endpoint, $params = []) {
    $url = url('ajax/' . $endpoint);
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    return $url;
}

