<?php
/**
 * Create Permit Redirect
 * 
 * File Path: /create-permit.php
 * Description: Redirects QR code scans to the proper Slim route
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * This file handles QR code scans and redirects to the correct permit creation route.
 * QR codes link to: /create-permit.php?template=hot-works-v1
 * This redirects to: /new/hot-works-v1
 */

// Get the template ID from query parameter
$templateId = $_GET['template'] ?? null;

if (!$templateId) {
    // No template specified - redirect to dashboard
    header('Location: /dashboard.php');
    exit;
}

// Redirect to the Slim route for creating permits
// /new/{templateId} is the route defined in src/routes.php
header('Location: /new/' . urlencode($templateId));
exit;