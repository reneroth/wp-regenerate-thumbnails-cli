<?php
namespace WpRegenerateThumbnailsCli;

class RegeneratorException extends \Exception { }

class Regenerator
{
    public $width = 40;

    public function __construct($wpRoot, $remove=false,
                                $debug=false, $silent=true)
    {
        $this->root = $wpRoot;
        $this->silent = $silent;
        $this->remove = $remove;
        $this->debug = $debug;
        // Load Wordpress
        $this->msg('Loading Wordpress');
        define('BASE_PATH', $this->root);
        define('WP_USE_THEMES', false);
        // Require main wp loader
        require_once($this->makePath($this->root, 'wp-load.php'));
        // Require attachment functions
        require_once($this->makePath(
            $this->root,
            'wp-admin',
            'includes',
            'image.php'
        ));
        $uploadDir = \wp_upload_dir();
        if ($uploadDir['error']) {
            $err = 'Upload dir error: '.$uploadDir['error'];
            $this->msg($err);
            throw RegeneratorException($err);
        }
        $this->uploadDir = $uploadDir['basedir'];
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    // Print message
    protected function msg($text)
    {
        if (!$this->silent)
            echo $text."\n";
    }

    // Create path from array parts
    protected function makePath()
    {
        $processed = array();
        foreach (func_get_args() as $p) {
            if (empty($p))
                continue;
            if (empty($processed)) {
                $processed[] = rtrim($p, DIRECTORY_SEPARATOR);
            } else {
                $processed[] = trim($p, DIRECTORY_SEPARATOR);
            }
        }
        return implode(DIRECTORY_SEPARATOR, $processed);
    }

    // Start regeneration
    public function run()
    {
        $sql = "SELECT ID FROM ".$this->wpdb->posts." ".
            "WHERE post_type = 'attachment' ".
            "AND post_mime_type LIKE 'image/%' ORDER BY ID";
        $images = $this->wpdb->get_results($sql);
        $overall = count($images);
        $counter = 0;
        $errors = array();
        foreach ($images as $image) {
            // Get attachment path
            $path = get_attached_file($image->ID);
            $skip = false;
            $error = null;
            if ($path === false || !file_exists($path)) {
                $error = 'Error: file does not exists for ID '.$image->ID;
                $skip = true;
            }
            // Generate new metadata
            $metadata = \wp_generate_attachment_metadata($image->ID, $path);
            if (!$skip && empty($metadata)) {
                $error = 'Error: metadata error for ID '.$image->ID;
                $skip = true;
            }
            if (!$skip && is_wp_error($metadata)) {
                $error = 'Error: metadata error for ID '.
                    $image->ID.': "'.
                    $metadata->get_error_message().'"';
                $skip = true;
            }
            // Update metadata
            if (!$skip) {
                \wp_update_attachment_metadata($image->ID, $metadata);
                if ($this->remove)
                    $this->removeOld($metadata);
            }
            // Show progress
            if ($error !== null) {
                $errors[] = $error;
                $this->msg("\n".$error);
            }
            $count++;
            $this->printProgress($count, $overall);
        }
        $this->msg('');         // End
        return $errors;
    }

    // Remove old thumbnails
    public function removeOld($metadata)
    {
        $file = new \SplFileInfo($metadata['file']);

        $dir = $this->makePath($this->uploadDir, dirname($metadata['file']));

        // Get regex for files
        $ext = $file->getExtension();
        $basename = $file->getBasename('.'.$ext);
        $regex = '_^'.          // O_o
               preg_quote($basename, '_').
               '\-[\d]+x[\d]+\.'.
               preg_quote($ext, '_').
               '$_';

        // Create list of existing files
        $dirIt = new \DirectoryIterator($dir);
        $regexIt = new \RegexIterator($dirIt, $regex);
        $existing = array();
        foreach ($regexIt as $f) {
            $existing[] = $f->getPathName();
        }

        // Get list of remaining files
        $remaining = array(
            $this->makePath($dir, $file->getFilename())
        );
        foreach ($metadata['sizes'] as $k => $v) {
            $remaining[] = $this->makePath($dir, $v['file']);
        }

        // Delete old
        foreach (array_diff($existing, $remaining) as $f) {
            if ($this->debug) {
                $this->msg('DEBUG: removing '.$f);
            } else {
                unlink($f);
            }
        };
    }

    protected function printProgress($count, $overall) {
        if ($this->silent)
            return;
        $percent = $count / $overall;
        echo "\r[";
        for ($i = 0; $i < round($this->width * $percent); $i++) {
            echo '#';
        }
        for ($i = round($this->width * $percent); $i < $this->width; $i++) {
            echo '-';
        }
        echo '] '.(round($percent * 100))."%";
    }
}


// Usage text
const HELP = <<<EOD
Usage:
php /path/to/wp-regenerate-thumbnails-cli.php -p|--path /path/to/wp/root/
    -p|--path -- path to Wordpress root directory (required)
    -h|--help -- print this message and exit
    -s|--silent -- don't print messages (except php errors/warnings)
    -r|--remove -- remove old images (warning, experimental)
    -d|--debug -- don't really remove images, just print filenames
EOD;


// Print text to terminal
function printc($text, $silent=false) {
    if (!$silent)
        echo $text."\n";
}


// Check if option is provided
function hasOption($options, $short=null, $long=null)
{
    return (!empty(array_intersect(
        array($short, $long),
        array_keys($options)
    )));
}

// Command line entry point
function main() {
    $options = getopt("hdsrp:", array(
        'help',
        'debug',
        'silent',
        'remove',
        'path:',
    ));

    // Check if help requested
    if (hasOption($options, 'h', 'help')) {
        printc(HELP);
        die();
    }

    if (!hasOption($options, 'p', 'path')) {
        printc("Error: path required\n");
        printc(HELP);
        die();
    }

    $silent = hasOption($options, 's', 'silent');
    $debug = hasOption($options, 'd', 'debug');
    // Disable silent on debug
    if ($debug)
        $silent = false;
    $remove = hasOption($options, 'r', 'remove');

    // Process path
    $path = array_key_exists('p', $options) ? $options['p'] : $options['path'];
    printc('Using path: '.$path, $silent);
    printc('Remove is: '.($remove ? 'on' : 'off'), $silent);
    printc('Remove debug is: '.($debug ? 'on' : 'off'), $silent);
    $path = realpath($path);
    if ($path === NULL || !is_dir($path)) {
        printc('Error: directory does not exists');
        die();
    }
    // Add directory separator to path
    $path = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

    $regenerator = new Regenerator($path, $remove, $debug, $silent);
    $regenerator->run();
}

// Run main if used from CLI
if (!count(debug_backtrace())) {
    main();
}
