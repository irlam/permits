<?php
/**
 * Permits System - Data Export Manager
 * 
 * Description: Handles exporting permit data to various formats
 * Name: Export.php
 * Last Updated: 21/10/2025 21:03:42 (UK)
 * Author: irlam
 * 
 * Purpose:
 * - Export permits to CSV format
 * - Export to PDF reports
 * - Generate Excel spreadsheets
 * - Custom column selection
 * 
 * Features:
 * - CSV export with custom delimiters
 * - Flexible column selection
 * - Data filtering support
 * - Memory-efficient streaming for large datasets
 */

namespace Permits;

use PDO;

/**
 * Data export manager for the Permits system
 */
class Export {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;
    
    /**
     * Constructor
     * 
     * @param Db $db Database connection wrapper
     */
    public function __construct(Db $db) {
        $this->pdo = $db->pdo;
    }
    
    /**
     * Export permits to CSV format
     * 
     * @param array $filters Optional filters to apply
     * @param array $columns Columns to include in export
     * @return void Outputs CSV directly to browser
     */
    public function exportToCSV(array $filters = [], array $columns = []): void {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="permits-export-' . date('Y-m-d-His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Default columns if none specified
        if (empty($columns)) {
            $columns = [
                'id', 'ref', 'template_id', 'site_block', 'status',
                'valid_from', 'valid_to', 'created_at', 'updated_at'
            ];
        }
        
        // Write UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write header row
        fputcsv($output, array_map(function($col) {
            return ucwords(str_replace('_', ' ', $col));
        }, $columns));
        
        // Build query with filters
        $where = [];
        $binds = [];
        
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = "(ref LIKE ? OR site_block LIKE ? OR metadata LIKE ?)";
            $binds[] = $search;
            $binds[] = $search;
            $binds[] = $search;
        }
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $binds[] = $filters['status'];
        }
        
        if (!empty($filters['template'])) {
            $where[] = "template_id = ?";
            $binds[] = $filters['template'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $binds[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $binds[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Select only requested columns
        $selectColumns = implode(', ', array_map(function($col) {
            return $col === 'metadata' ? $col : $col;
        }, $columns));
        
        $sql = "SELECT $selectColumns FROM forms $whereClause ORDER BY created_at DESC";
        
        // Execute query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($binds);
        
        // Write data rows
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Process metadata if included
            if (isset($row['metadata'])) {
                // Parse JSON metadata and extract key fields
                $metadata = json_decode($row['metadata'], true);
                if (is_array($metadata)) {
                    // Keep only a simplified version for CSV
                    $row['metadata'] = $this->simplifyMetadata($metadata);
                }
            }
            
            // Write row
            fputcsv($output, array_values($row));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Get export statistics
     * 
     * @param array $filters Optional filters to apply
     * @return array Statistics about the export
     */
    public function getExportStats(array $filters = []): array {
        $where = [];
        $binds = [];
        
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $where[] = "(ref LIKE ? OR site_block LIKE ? OR metadata LIKE ?)";
            $binds[] = $search;
            $binds[] = $search;
            $binds[] = $search;
        }
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $binds[] = $filters['status'];
        }
        
        if (!empty($filters['template'])) {
            $where[] = "template_id = ?";
            $binds[] = $filters['template'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $binds[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $binds[] = $filters['date_to'] . ' 23:59:59';
        }
        
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as total FROM forms $whereClause");
        $stmt->execute($binds);
        
        return [
            'total_records' => (int)$stmt->fetchColumn(),
            'filters_applied' => !empty($filters),
        ];
    }
    
    /**
     * Simplify metadata for CSV export
     * 
     * @param array $metadata Full metadata array
     * @return string Simplified string representation
     */
    private function simplifyMetadata(array $metadata): string {
        $parts = [];
        
        if (isset($metadata['meta']['contractor'])) {
            $parts[] = 'Contractor: ' . $metadata['meta']['contractor'];
        }
        
        if (isset($metadata['meta']['workType'])) {
            $parts[] = 'Work: ' . $metadata['meta']['workType'];
        }
        
        if (isset($metadata['meta']['hazards'])) {
            $parts[] = 'Hazards: ' . $metadata['meta']['hazards'];
        }
        
        return implode(' | ', $parts);
    }
}
