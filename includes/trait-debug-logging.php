<?php

// v1.1.0

trait TL_Debug_Logging
{

    /**
     * Plugin Helper: Debug logging within the plugin folder.
     *
     * @since 0.1.0
     * @param String $text
     * @return none
     **/
    function dlog($text, $method = null)
    {

        if (!$this->debug_mode) {
            return;
        }
        // open log file
        try
        {
            $filename = "inz-debug-log.txt";
            $fh = fopen(plugin_dir_path(dirname(__FILE__)) . $filename, "a");

            if (false == $fh) {
                error_log(__METHOD__ . " - Could not open log file: " . plugin_dir_path(__FILE__) . $filename, 0);
                throw new Exception('(ewi) Could not open log file.');
            }

            if (!is_null($method)) {
                $text = '' . $method . ' => ' . $text;
            }

            $fw = fwrite($fh, date("d-m-Y, H:i") . " - $text\n");

            if (false == $fw) {
                error_log(__METHOD__ . " - Could not write file!", 0);
            } else {
                fclose($fh);
            }

        } catch (Exception $e) {
            if (true === WP_DEBUG) {
                error_log(__METHOD__ . ' - ' . $text);
            }
        }

    }

}
