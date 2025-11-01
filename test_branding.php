<?php
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
use Permits\SystemSettings;

echo "Company Name: " . (SystemSettings::companyName($db) ?? 'Not set') . "\n";
echo "Company Logo Path: " . (SystemSettings::companyLogoPath($db) ?? 'Not set') . "\n";
?>