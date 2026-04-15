<?php
/**
 * Export class for handling data exports
 */

if (!defined('ABSPATH')) {
    exit;
}

class IMS_Export {
    
    public function export_to_csv($type = 'all') {
        global $wpdb;
        
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/ims-exports/';
        
        // Create export directory if it doesn't exist
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $timestamp = date('Y-m-d-H-i-s');
        $filename = "ims-export-{$type}-{$timestamp}.csv";
        $filepath = $export_dir . $filename;
        
        $file = fopen($filepath, 'w');
        
        if (!$file) {
            return false;
        }
        
        switch ($type) {
            case 'imports':
                $this->export_imports_csv($file);
                break;
            case 'stock':
                $this->export_stock_csv($file);
                break;
            case 'chopped':
                $this->export_chopped_csv($file);
                break;
            case 'all':
            default:
                $this->export_all_csv($file);
                break;
        }
        
        fclose($file);
        
        // Return download URL
        $file_url = $upload_dir['baseurl'] . '/ims-exports/' . $filename;
        return $file_url;
    }
    
    private function export_imports_csv($file) {
        global $wpdb;
        
        // Write header
        fputcsv($file, array(
            'ID', 'Product', 'Quantity', 'Staff Name', 'Date', 'Time', 'Processed', 'Created At'
        ));
        
        // Get data
        $records = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ims_imports ORDER BY date_created DESC"
        );
        
        foreach ($records as $record) {
            fputcsv($file, array(
                $record->id,
                $record->product,
                $record->quantity,
                $record->staff_name,
                date('Y-m-d', strtotime($record->date_created)),
                date('H:i:s', strtotime($record->timestamp_created)),
                $record->processed ? 'Yes' : 'No',
                $record->created_at
            ));
        }
    }
    
    private function export_stock_csv($file) {
        global $wpdb;
        
        // Write header
        fputcsv($file, array(
            'ID', 'Product', 'Opening Packs', 'Added Packs', 'Used Packs', 
            'Closing Packs', 'Staff Name', 'Date', 'Time', 'Remarks', 'Created At'
        ));
        
        // Get data
        $records = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ims_stock ORDER BY date_created DESC"
        );
        
        foreach ($records as $record) {
            fputcsv($file, array(
                $record->id,
                $record->product,
                $record->opening_packs,
                $record->added_packs,
                $record->used_packs,
                $record->closing_packs,
                $record->staff_name,
                date('Y-m-d', strtotime($record->date_created)),
                date('H:i:s', strtotime($record->timestamp_created)),
                $record->remarks,
                $record->created_at
            ));
        }
    }
    
    private function export_chopped_csv($file) {
        global $wpdb;
        
        // Write header
        fputcsv($file, array(
            'ID', 'Fruit', 'Opening (Whole)', 'Import (Whole)', 'Prepared (Whole)', 
            'Closing (Whole)', 'Pack(s) Gotten', 'Staff Name', 'Date', 'Time', 'Remarks', 'Created At'
        ));
        
        // Get data
        $records = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ims_chopped ORDER BY date_created DESC"
        );
        
        foreach ($records as $record) {
            fputcsv($file, array(
                $record->id,
                $record->fruit,
                $record->opening_whole,
                $record->import_whole,
                $record->prepared_whole,
                $record->closing_whole,
                $record->packs_gotten,
                $record->staff_name,
                date('Y-m-d', strtotime($record->date_created)),
                date('H:i:s', strtotime($record->timestamp_created)),
                $record->remarks,
                $record->created_at
            ));
        }
    }
    
    private function export_all_csv($file) {
        // Write a comprehensive export with all data
        fputcsv($file, array('IMPORT RECORDS'));
        fputcsv($file, array()); // Empty row
        
        $this->export_imports_csv($file);
        
        fputcsv($file, array()); // Empty row
        fputcsv($file, array('STOCK RECORDS'));
        fputcsv($file, array()); // Empty row
        
        $this->export_stock_csv($file);
        
        fputcsv($file, array()); // Empty row
        fputcsv($file, array('CHOPPED RECORDS'));
        fputcsv($file, array()); // Empty row
        
        $this->export_chopped_csv($file);
    }
    
    public function export_to_pdf($type = 'all') {
        // This would require a PDF library like TCPDF or FPDF
        // For now, we'll return false as it's optional
        return false;
    }
    
    public function cleanup_old_exports() {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/ims-exports/';
        
        if (!file_exists($export_dir)) {
            return;
        }
        
        $files = glob($export_dir . '*.csv');
        $cutoff_time = time() - (7 * 24 * 60 * 60); // 7 days ago
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
            }
        }
    }
}
?>