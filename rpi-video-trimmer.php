<?php
/**
 * Plugin Name: RPI Video Trimmer
 * Description: Trims videos in the Media Library with FFmpeg stream copy and saves the result as a new attachment.
 * Version: 1.0.0
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 * Text Domain: rpi-video-trimmer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class RPI_Video_Trimmer
{
    const ACTION = 'rpi_video_trimmer';

    public static function init()
    {
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'add_fields'), 10, 2);
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'handle_ajax'));
        add_action('admin_head', array(__CLASS__, 'print_styles'));
        add_action('admin_footer', array(__CLASS__, 'print_script'));
    }

    private static function is_video($post)
    {
        return $post && 'attachment' === $post->post_type && 0 === strpos((string) get_post_mime_type($post), 'video/');
    }

    public static function add_fields($form_fields, $post)
    {
        if (!self::is_video($post) || !current_user_can('edit_post', $post->ID)) {
            return $form_fields;
        }

        $id = (int) $post->ID;
        $nonce = wp_create_nonce(self::ACTION . '_' . $id);
        $uid = 'rpi-vt-' . $id;

        ob_start();
        ?>
        <div class="rpi-vt" data-id="<?php echo esc_attr($id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <p id="<?php echo esc_attr($uid); ?>-help" class="rpi-vt-help">
                <?php esc_html_e('Enter the start and end time of the part to keep, in seconds. A new video is created and the original file is kept. Cuts snap to the nearest keyframe because the video is not re-encoded.', 'rpi-video-trimmer'); ?>
            </p>
            <p class="rpi-vt-row">
                <label for="<?php echo esc_attr($uid); ?>-start"><?php esc_html_e('Start time (seconds)', 'rpi-video-trimmer'); ?></label>
                <input type="number" step="0.1" min="0" value="0" id="<?php echo esc_attr($uid); ?>-start" class="rpi-vt-start" aria-describedby="<?php echo esc_attr($uid); ?>-help">
            </p>
            <p class="rpi-vt-row">
                <label for="<?php echo esc_attr($uid); ?>-end"><?php esc_html_e('End time (seconds)', 'rpi-video-trimmer'); ?></label>
                <input type="number" step="0.1" min="0" value="" id="<?php echo esc_attr($uid); ?>-end" class="rpi-vt-end" aria-describedby="<?php echo esc_attr($uid); ?>-help">
            </p>
            <p class="rpi-vt-row">
                <button type="button" class="button button-primary rpi-vt-submit"><?php esc_html_e('Trim video', 'rpi-video-trimmer'); ?></button>
            </p>
            <div class="rpi-vt-status" role="status" aria-live="polite" aria-atomic="true"></div>
        </div>
        <?php
        $html = ob_get_clean();

        $form_fields['rpi_video_trimmer'] = array(
            'label' => __('Trim Video', 'rpi-video-trimmer'),
            'input' => 'html',
            'html' => $html,
        );

        return $form_fields;
    }

    private static function fail($message, $status = 400)
    {
        wp_send_json_error(array('message' => $message), $status);
    }

    private static function find_ffmpeg()
    {
        $candidates = array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/bin/ffmpeg');
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return '';
    }

    private static function format_time($seconds)
    {
        return number_format($seconds, 3, '.', '');
    }

    public static function handle_ajax()
    {
        $id = isset($_POST['id']) ? absint(wp_unslash($_POST['id'])) : 0;

        if (!$id || !check_ajax_referer(self::ACTION . '_' . $id, 'nonce', false)) {
            self::fail(__('Security check failed. Reload the page and try again.', 'rpi-video-trimmer'), 403);
        }

        if (!current_user_can('edit_post', $id) || !current_user_can('upload_files')) {
            self::fail(__('You do not have permission to trim this video.', 'rpi-video-trimmer'), 403);
        }

        $post = get_post($id);
        if (!self::is_video($post)) {
            self::fail(__('The selected file is not a video.', 'rpi-video-trimmer'));
        }

        $start_raw = isset($_POST['start']) ? sanitize_text_field(wp_unslash($_POST['start'])) : '';
        $end_raw = isset($_POST['end']) ? sanitize_text_field(wp_unslash($_POST['end'])) : '';

        if (!is_numeric($start_raw) || !is_numeric($end_raw)) {
            self::fail(__('Start and end times must be numbers.', 'rpi-video-trimmer'));
        }

        $start = (float) $start_raw;
        $end = (float) $end_raw;

        if ($start < 0 || $end <= $start) {
            self::fail(__('The end time must be greater than the start time, and the start time cannot be negative.', 'rpi-video-trimmer'));
        }

        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta) && !empty($meta['length']) && $start >= (float) $meta['length']) {
            self::fail(__('The start time is beyond the end of the video.', 'rpi-video-trimmer'));
        }

        $input = get_attached_file($id);
        if (!$input || !is_readable($input)) {
            self::fail(__('The original video file could not be read.', 'rpi-video-trimmer'));
        }

        $ffmpeg = self::find_ffmpeg();
        if ('' === $ffmpeg) {
            self::fail(__('FFmpeg was not found on the server.', 'rpi-video-trimmer'), 500);
        }

        $info = pathinfo($input);
        $extension = isset($info['extension']) ? $info['extension'] : 'mp4';
        $dir = $info['dirname'];
        $name = wp_unique_filename($dir, $info['filename'] . '-trim.' . $extension);
        $output = trailingslashit($dir) . $name;

        $command = array(
            $ffmpeg,
            '-y',
            '-ss', self::format_time($start),
            '-i', $input,
            '-t', self::format_time($end - $start),
            '-c', 'copy',
            $output,
        );

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = function_exists('proc_open') ? proc_open($command, $descriptors, $pipes) : false;
        if (!is_resource($process)) {
            self::fail(__('FFmpeg could not be started.', 'rpi-video-trimmer'), 500);
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if (0 !== $code || !is_file($output) || filesize($output) <= 0) {
            if (is_file($output)) {
                wp_delete_file($output);
            }
            self::fail(__('FFmpeg failed to trim the video.', 'rpi-video-trimmer'), 500);
        }

        $filetype = wp_check_filetype($name);
        $attachment = array(
            'post_mime_type' => $filetype['type'] ? $filetype['type'] : $post->post_mime_type,
            'post_title' => sprintf(
                __('%s (trimmed)', 'rpi-video-trimmer'),
                $post->post_title
            ),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post->post_parent,
        );

        $new_id = wp_insert_attachment($attachment, $output, $post->post_parent, true);
        if (is_wp_error($new_id)) {
            wp_delete_file($output);
            self::fail($new_id->get_error_message(), 500);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        wp_update_attachment_metadata($new_id, wp_generate_attachment_metadata($new_id, $output));

        wp_send_json_success(array(
            'id' => $new_id,
            'message' => sprintf(
                __('Trimming finished. A new video was added to the Media Library with ID %d.', 'rpi-video-trimmer'),
                $new_id
            ),
            'editUrl' => get_edit_post_link($new_id, 'raw'),
        ));
    }

    public static function print_styles()
    {
        ?>
        <style>
            .rpi-vt { max-width: 32em; }
            .rpi-vt-row { margin: 0 0 12px; }
            .rpi-vt label { display: block; font-weight: 600; margin-bottom: 4px; }
            .rpi-vt input[type="number"] { width: 10em; }
            .rpi-vt-help { margin: 0 0 12px; }
            .rpi-vt-status { margin-top: 8px; padding: 6px 10px; border-left: 4px solid #1d2327; font-weight: 600; }
            .rpi-vt-status:empty { display: none; }
            .rpi-vt button:focus, .rpi-vt input:focus { outline: 2px solid #1d2327; outline-offset: 2px; }
        </style>
        <?php
    }

    public static function print_script()
    {
        if (!current_user_can('upload_files')) {
            return;
        }
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION,
            'i18n' => array(
                'working' => __('Trimming started. Please wait, this can take a moment.', 'rpi-video-trimmer'),
                'invalid' => __('Error: enter a start time of 0 or more and an end time greater than the start time.', 'rpi-video-trimmer'),
                'failed' => __('Error: the request failed. Check your connection and try again.', 'rpi-video-trimmer'),
                'errorPrefix' => __('Error: ', 'rpi-video-trimmer'),
            ),
        );
        ?>
        <script>
            (function () {
                var config = <?php echo wp_json_encode($config); ?>;

                function setStatus(box, text) {
                    var status = box.querySelector('.rpi-vt-status');
                    status.textContent = '';
                    window.setTimeout(function () {
                        status.textContent = text;
                    }, 50);
                }

                document.addEventListener('click', function (event) {
                    var button = event.target.closest ? event.target.closest('.rpi-vt-submit') : null;
                    if (!button) {
                        return;
                    }
                    event.preventDefault();

                    var box = button.closest('.rpi-vt');
                    var start = box.querySelector('.rpi-vt-start').value;
                    var end = box.querySelector('.rpi-vt-end').value;
                    var startNum = parseFloat(start);
                    var endNum = parseFloat(end);

                    if (isNaN(startNum) || isNaN(endNum) || startNum < 0 || endNum <= startNum) {
                        setStatus(box, config.i18n.invalid);
                        return;
                    }

                    var body = new URLSearchParams();
                    body.append('action', config.action);
                    body.append('id', box.getAttribute('data-id'));
                    body.append('nonce', box.getAttribute('data-nonce'));
                    body.append('start', start);
                    body.append('end', end);

                    button.disabled = true;
                    box.setAttribute('aria-busy', 'true');
                    setStatus(box, config.i18n.working);

                    fetch(config.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString()
                    }).then(function (response) {
                        return response.json();
                    }).then(function (result) {
                        if (result && result.success) {
                            setStatus(box, result.data.message);
                        } else {
                            var message = result && result.data && result.data.message ? result.data.message : config.i18n.failed;
                            setStatus(box, config.i18n.errorPrefix + message);
                        }
                    }).catch(function () {
                        setStatus(box, config.i18n.failed);
                    }).then(function () {
                        button.disabled = false;
                        box.removeAttribute('aria-busy');
                    });
                });
            }());
        </script>
        <?php
    }
}

RPI_Video_Trimmer::init();
