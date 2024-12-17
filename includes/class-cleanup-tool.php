<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Clean_Master {

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_clean_action', array( $this, 'handle_ajax_cleanup' ) );
        add_action( 'wp_ajax_toggle_scheduled_cleanup', array( $this, 'toggle_scheduled_cleanup' ) );

        // Scheduled cleanup cron jobs
        add_action( 'wp_clean_master_daily_event', array( $this, 'run_scheduled_cleanup' ) );
        add_action( 'wp_clean_master_weekly_event', array( $this, 'run_scheduled_cleanup' ) );
    }

    /**
     * Add Admin Menu for Cleanup Tool
     */
    public function add_admin_menu() {
        add_menu_page(
            'WP Clean Master',
            'WP Clean Master',
            'manage_options',
            'wp-clean-master',
            array( $this, 'cleanup_tool_page' ),
            'dashicons-trash',
            90
        );
    }

    /**
     * Enqueue Admin Assets (CSS & JS)
     */
    public function enqueue_assets() {
        wp_enqueue_style( 'wp-clean-master-styles', WP_CLEAN_MASTER_URL . 'assets/css/styles.css' );
        wp_enqueue_script( 'wp-clean-master-js', WP_CLEAN_MASTER_URL . 'assets/js/main.js', array( 'jquery' ), null, true );
        wp_localize_script( 'wp-clean-master-js', 'cleanupMasterAjax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cleanup_action_nonce' ),
        ) );
    }

    /**
     * Render Admin Dashboard Page
     */
    public function cleanup_tool_page() {
        $stats = $this->get_cleanup_stats(); // Fetch stats
        include WP_CLEAN_MASTER_PATH . 'views/cleanup-tool-dashboard.php'; // Include view file
    }

    /**
     * Handle AJAX Cleanup Actions
     */
    public function handle_ajax_cleanup() {
        check_ajax_referer( 'cleanup_action_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized request.' ) );
            return;
        }

        if ( isset( $_POST['cleanup_action'] ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['cleanup_action'] ) );
        } else {
            wp_send_json_error( array( 'message' => 'Invalid request. Action not specified.' ) );
            return;
        }
        

        $count = 0;
        switch ( $action ) {
            case 'clean_drafts':
                $count = $this->clean_drafts();
                break;
            case 'clean_trashed_posts':
                $count = $this->clean_trashed_posts();
                break;
            case 'clean_unapproved_comments':
                $count = $this->clean_unapproved_comments();
                break;
            case 'clean_orphaned_media':
                $count = $this->clean_orphaned_media();
                break;
            case 'clean_post_revisions':
                $count = $this->clean_post_revisions();
                break;
            case 'clean_transients':
                $count = $this->clean_transients();
                break;
            case 'clean_spam_comments':
                $count = $this->clean_spam_comments();
                break;
            default:
                wp_send_json_error( array( 'message' => 'Invalid action.' ) );
        }

        $this->insert_cleanup_log( ucfirst( str_replace( '_', ' ', $action ) ), $count );
        wp_send_json_success( array( 'message' => "Cleaned {$count} items successfully." ) );
    }

    /**
     * Scheduled Cleanup Toggle
     */
    public function toggle_scheduled_cleanup() {
        check_ajax_referer( 'cleanup_action_nonce', 'nonce' );
    
        // Validate and sanitize 'schedule'
        if ( isset( $_POST['schedule'] ) ) {
            $schedule = sanitize_text_field( wp_unslash( $_POST['schedule'] ) );
        } else {
            wp_send_json_error( array( 'message' => 'Invalid request. Schedule not specified.' ) );
            return;
        }
    
        // Validate and sanitize 'enabled'
        if ( isset( $_POST['enabled'] ) ) {
            $enabled = filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN );
        } else {
            wp_send_json_error( array( 'message' => 'Invalid request. Enabled status not specified.' ) );
            return;
        }
    
        $hook = ( $schedule === 'daily' ) ? 'wp_clean_master_daily_event' : 'wp_clean_master_weekly_event';
        $option = "wp_clean_master_{$schedule}";
    
        if ( $enabled ) {
            update_option( $option, 1 );
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event( time(), $schedule, $hook );
            }
        } else {
            update_option( $option, 0 );
            wp_clear_scheduled_hook( $hook );
        }
    
        wp_send_json_success( array( 'message' => ucfirst( $schedule ) . ' schedule updated successfully.' ) );
    }
    

    /**
    * Get Cleanup Stats
    */
    private function get_cleanup_stats() {
        return array(
        'drafts'              => count( get_posts( array( 'post_status' => 'draft', 'numberposts' => -1 ) ) ),
        'trashed'             => count( get_posts( array( 'post_status' => 'trash', 'numberposts' => -1 ) ) ),
        'unapproved_comments' => wp_count_comments()->moderated,
        'orphaned_media'      => $this->get_orphaned_media_count(),
        'post_revisions'      => count( get_posts( array( 'post_type' => 'revision', 'numberposts' => -1 ) ) ),
        'transients'          => $this->get_transient_count(),
        'spam_comments'       => wp_count_comments()->spam,
    );
    }
    private function clean_drafts() {
        return $this->delete_posts_by_status( 'draft' );
    }

    private function clean_trashed_posts() {
        return $this->delete_posts_by_status( 'trash' );
    }

    private function clean_unapproved_comments() {
        // Fetch all unapproved comments
        $unapproved_comments = get_comments( array(
            'status' => 'hold', // "hold" is the status for unapproved comments
            'number' => 0,      // Get all matching comments
        ) );
    
        // Delete each unapproved comment
        $deleted_count = 0;
        foreach ( $unapproved_comments as $comment ) {
            if ( wp_delete_comment( $comment->comment_ID, true ) ) {
                $deleted_count++;
            }
        }
    
        return $deleted_count; // Return the number of deleted comments
    }
    

/**
 * Clean Orphaned Media
 */
    private function clean_orphaned_media() {
        // Cache key for orphaned media
        $cache_key = 'orphaned_media_ids';
        $attachments = wp_cache_get( $cache_key );

    if ( false === $attachments ) {
            // Use get_posts() instead of direct SQL query
            $attachments = get_posts( array(
                'post_type'   => 'attachment',
                'post_parent' => 0,
                'fields'      => 'ids',
                'numberposts' => -1,
            ) );

        // Cache the results to avoid redundant queries
         wp_cache_set( $cache_key, $attachments );
    }

        if ( empty( $attachments ) ) {
            return 0;
    }

    // Ensure all attachment IDs are integers
        $attachments = array_map( 'intval', $attachments );

        // Used attachments in content
        $used_in_content_cache_key = 'used_in_content_ids';
        $used_in_content = wp_cache_get( $used_in_content_cache_key );

    if ( false === $used_in_content ) {
        $used_in_content = array();

        // Search for attachments in post content
        foreach ( $attachments as $attachment_id ) {
            $file = esc_sql( get_post_meta( $attachment_id, '_wp_attached_file', true ) );

            if ( ! empty( $file ) ) {
                $query = new WP_Query( array(
                    's'           => $file,
                    'post_type'   => 'any',
                    'fields'      => 'ids',
                    'numberposts' => -1,
                ) );

                $used_in_content = array_merge( $used_in_content, $query->posts );
            }
        }

        // Cache the result
        wp_cache_set( $used_in_content_cache_key, $used_in_content );
    }

        // Used attachments as featured images
        $used_as_featured_cache_key = 'used_as_featured_ids';
        $used_as_featured = wp_cache_get( $used_as_featured_cache_key );

    if ( false === $used_as_featured ) {
        $used_as_featured = get_posts( array(
            'post_type'   => 'any',
            'meta_key'    => '_thumbnail_id',
            'meta_value'  => $attachments,
            'fields'      => 'ids',
            'numberposts' => -1,
        ) );

        // Cache the result
        wp_cache_set( $used_as_featured_cache_key, $used_as_featured );
    }

        // Merge all used attachments and ensure IDs are integers
        $used_attachments = array_map( 'intval', array_unique( array_merge( $used_in_content, $used_as_featured ) ) );

        // Find orphaned attachments
        $orphaned_attachments = array_diff( $attachments, $used_attachments );

        // // Delete orphaned attachments
        foreach ( $orphaned_attachments as $attachment_id ) {
            wp_delete_attachment( $attachment_id, true );
        }

        return count( $orphaned_attachments );
    }



    private function clean_post_revisions() {
        return $this->delete_posts_by_status( 'revision' );
    }

    private function clean_spam_comments() {
        // Check for cached spam comments
        $cache_key = 'spam_comment_ids';
        $spam_comments = wp_cache_get( $cache_key );
    
        if ( false === $spam_comments ) {
            // Use get_comments() to retrieve spam comments
            $spam_comments = get_comments( array(
                'status' => 'spam',
                'fields' => 'ids',
                'number' => 0, // Fetch all spam comments
            ) );
    
            // Cache the spam comment IDs
            wp_cache_set( $cache_key, $spam_comments );
        }
    
        // Delete spam comments if any exist
        if ( ! empty( $spam_comments ) ) {
            foreach ( $spam_comments as $comment_id ) {
                wp_delete_comment( $comment_id, true );
            }
        }
    
        return count( $spam_comments );
    }
    

    private function delete_posts_by_status( $status ) {
        $posts = get_posts( array( 'post_status' => $status, 'numberposts' => -1 ) );
        foreach ( $posts as $post ) {
            wp_delete_post( $post->ID, true );
        }
        return count( $posts );
    }

    /**
    * Get Count of Orphaned Media
    */
    private function get_orphaned_media_count() {
        // Cache key for orphaned media count
        $cache_key = 'orphaned_media_count';
        $orphaned_media_count = wp_cache_get( $cache_key );
        if ( false !== $orphaned_media_count ) {
            return $orphaned_media_count;
        }
    
        // Step 1: Fetch all attachment IDs without a post_parent
        $attachments = get_posts( array(
            'post_type'   => 'attachment',
            'post_parent' => 0,
            'fields'      => 'ids',
            'numberposts' => -1,
        ) );
    
        if ( empty( $attachments ) ) {
            wp_cache_set( $cache_key, 0 ); // Cache the result
            return 0;
        }
    
        // Ensure all attachment IDs are integers
        $attachments = array_map( 'intval', $attachments );
    
        // Step 2: Find attachments used in post content
        $used_in_content = array();
        foreach ( $attachments as $attachment_id ) {
            // Get the file path or URL
            $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
            if ( ! empty( $file ) ) {
                // Search for the file in post content
                $query = new WP_Query( array(
                    's'           => basename( $file ), // Search for the file name
                    'post_type'   => 'any',
                    'fields'      => 'ids',
                    'numberposts' => -1,
                ) );
    
                if ( ! empty( $query->posts ) ) {
                    $used_in_content[] = $attachment_id;
                }
    
                // Clean up the WP_Query instance
                wp_reset_postdata();
            }
        }
    
        // Step 3: Find attachments used as featured images
        $used_as_featured = get_posts( array(
            'post_type'   => 'any',
            'meta_key'    => '_thumbnail_id',
            'meta_value'  => $attachments,
            'fields'      => 'meta_value',
            'numberposts' => -1,
        ) );
    
        // Fix: Handle cases where objects might appear in the arrays
        $used_in_content_ids = array_map( function( $item ) {
            return is_object( $item ) && isset( $item->ID ) ? intval( $item->ID ) : intval( $item );
        }, $used_in_content );
    
        $used_as_featured_ids = array_map( function( $item ) {
            return is_object( $item ) && isset( $item->ID ) ? intval( $item->ID ) : intval( $item );
        }, $used_as_featured );
    
        // Merge and ensure all IDs are integers
        $used_attachments = array_map( 'intval', array_merge( $used_in_content_ids, $used_as_featured_ids ) );
    
        // Step 4: Identify unused (orphaned) attachments
        $orphaned_attachments = array_diff( $attachments, $used_attachments );
    
        // Cache the orphaned media count
        $orphaned_media_count = count( $orphaned_attachments );
        wp_cache_set( $cache_key, $orphaned_media_count );
    
        // Step 5: Optional - Delete orphaned attachments
        // foreach ( $orphaned_attachments as $attachment_id ) {
        //     wp_delete_attachment( $attachment_id, true );
        // }
    
        return $orphaned_media_count;
    }
    


 /**
 * Get Count of Expired Transients
 */
    private function get_transient_count() {
        global $wpdb;
        // Ensure proper transient timeout table query
        $count = $wpdb->get_var( 
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", 
            '_transient_timeout_%', time()
        ) 
    );
    return $count ? (int) $count : 0;
    }

    /**
 * Clean Expired Transients
 */
private function clean_transients() {
    global $wpdb;

    // Define placeholders and values for the first query
    $query = $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        '_transient_timeout_%',
        time()
    );
    $deleted_timeouts = $wpdb->query( $query ); // Execute query securely

    // Define placeholders and values for the second query
    $query_values = $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
        '_transient_%',
        '_transient_timeout_%'
    );
    $deleted_values = $wpdb->query( $query_values ); // Execute query securely

    // Return the total number of deleted transients
    return (int) ( $deleted_timeouts + $deleted_values );
}



    private function insert_cleanup_log( $action, $count ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'clean_master_logs', array(
            'cleanup_type'  => sanitize_text_field( $action ),
            'cleaned_count' => absint( $count ),
            'cleaned_on'    => current_time( 'mysql' ),
        ) );
    }

/**
 * Calculate Total Space Saved
 */
    public function calculate_total_space_saved() {
        global $wpdb;

        // Step 1: Fetch total space saved from cleanup logs
        $total_space_saved = (int) get_option( 'wp_clean_master_total_space_saved', 0 );

        // Step 2: Calculate orphaned media space saved
        $orphaned_media_space = 0;
        $media_ids = $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} 
         WHERE meta_key = '_wp_attached_file' 
         AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0)"
        );

        foreach ( $media_ids as $file ) {
        $file_path = wp_get_upload_dir()['basedir'] . '/' . $file;
        if ( file_exists( $file_path ) ) {
            $orphaned_media_space += filesize( $file_path );
        }
        }

        // Add orphaned media space saved to total space saved
        $total_space_saved += $orphaned_media_space;

        // Step 3: Save the updated value to the database
        update_option( 'wp_clean_master_total_space_saved', $total_space_saved );

        return $total_space_saved; // Return space in bytes
    }

}