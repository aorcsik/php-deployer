<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/vendor/autoload.php';

$app = new Application();
$app['debug'] = true;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/services/')) as $file) {
    if ($file->isFile() && $file->getExtension() == "yml") {
        $app->register(new DerAlex\Silex\YamlConfigServiceProvider($file->getPathname()));
    }
}

class Deployer {

    public static $TEMP_DIR = "/tmp/";

    private $service;

    private $config;

    public function __construct($service, $config) {
        $this->service = $service;
        $this->config = $config;
    }

    public function recursive_unlink($root_dir) {
        if (is_dir($root_dir)) {
            $root_dir = preg_replace("'/*$'", "/", $root_dir);  // add trailing slash
            $it = new RecursiveDirectoryIterator($root_dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file) {
                $path = $file->getRealPath();
                if ($file->isDir()){
                    rmdir($path);
                    $this->log("info", "rmdir: " . $path);
                } else {
                    unlink($path);
                    $this->log("info", "unlink: " . $path);
                }
            }
            rmdir($root_dir);
            $this->log("info", "rmdir: " . $root_dir);
        }
    }

    public function recurse_copy($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->recurse_copy($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }

    private function log($level, $message) {
        echo sprintf("%s [%s] %s\n", date("Y-m-d H:i:s"), $level, $message);
        ob_flush();
        flush();
    }

    private function fail($error_message=null) {
        $this->log("error", $error_message ? "FAILED: " . $error_message : "FAILED");
        return false;
    }

    private function success($return_value=null) {
        $this->log("info", "OK");
        if (isset($return_value)) {
            return $return_value;
        }
        return true;
    }

    private function move_artifact_to_temp($request) {
        $file = $request->files->get("artifact");
        if ($file) {
            $this->log("info", "Artifact uploaded");


            if (!is_dir(__DIR__ . self::$TEMP_DIR)) {
                @mkdir(__DIR__ . self::$TEMP_DIR, 0777, true);
            }
            $artifact_name = $file->getFilename();
            $artifact_path = __DIR__ . self::$TEMP_DIR . $artifact_name;
            $this->log("info", "Moving artifact " . $artifact_name . " to temp...");
            $file->move(__DIR__ . self::$TEMP_DIR, $artifact_name);
            if (file_exists($artifact_path)) {
                return $this->success($artifact_path);
            }
        }
        return $this->fail();
    }

    private function extract_artifact($artifact_path) {
        $this->log("info", "Extracting artifact...");
        if (!is_dir(__DIR__ . self::$TEMP_DIR)) {
            @mkdir(__DIR__ . self::$TEMP_DIR, 0777, true);
        }
        $archive = new Archive_Tar($artifact_path);
        $new_build_path = __DIR__ . self::$TEMP_DIR . basename($artifact_path) . ".deploy";
        if (!is_dir($new_build_path)) {
            @mkdir($new_build_path, 0777, true);
        }
        if ($archive->extract($new_build_path)) {
            return $this->success($new_build_path);
        }
        return $this->fail($archive->error_object->getMessage());
    }

    private function delete_artifact($artifact_path) {
        $this->log("info", "Deleting artifact...");
        if (file_exists($artifact_path)) {
            unlink($artifact_path);
        }
        if (!file_exists($artifact_path)) {
            return $this->success();
        }
        return $this->fail();
    }

    private function add_extra_files($new_build_path) {
        if (!empty($this->config['extra_files'])) {
            $this->log("info", "Adding extra files...");
            foreach ($this->config['extra_files'] as $filename) {
                $extra_file_path = __DIR__ . "/services/" . $this->service . "/" . $filename;
                if (file_exists($extra_file_path)) {
                    $this->log("info", "Adding extra file: " . $filename);
                    $extra_dir = dirname($new_build_path . "/" . $filename);
                    if (!is_dir($extra_dir)) {
                        @mkdir($extra_dir, 0777, true);
                    }
                    copy($extra_file_path, $new_build_path . "/" . $filename);
                    if (!file_exists($new_build_path . "/" . $filename)) {
                        $this->fail();
                    }
                    $this->success();
                } else {
                    $this->log("warning", "Missing extra file: " . $filename);
                }
            }
        }
        return true;
    }

    private function add_optional_artifacts($new_build_path, $request) {
        $exclude_optional = array();
        if (!empty($this->config['optional_artifacts'])) {
            $this->log("info", "Adding optional artifacts...");
            foreach ($this->config['optional_artifacts'] as $name => $relative_path) {
                $file = $request->files->get($name);
                $new_data = false;
                if ($file) {
                    $artifact_name = $this->service . "-" . $name . "-artifact.tar.gz";
                    $artifact_path = __DIR__ . self::$TEMP_DIR . $artifact_name;
                    $this->log("info", "Checking artifact: " . $artifact_name);
                    $new_data = !file_exists($artifact_path);
                    if (!$new_data) {
                        $old_md5 = md5_file($artifact_path);
                        $this->log("info", "Old MD5: >" . $old_md5 . "<");
                        $new_md5 = md5_file($file->getRealPath());
                        $this->log("info", "New MD5: >" . $new_md5 . "<");
                        if (strcmp($new_md5, $old_md5)) {
                            $this->log("info", "MD5 Different: " . strcmp($new_md5, $old_md5));
                            $new_data = true;
                        }
                    }
                }
                if ($new_data === true) {
                    if (file_exists($artifact_path)) {
                        unlink($artifact_path);
                    }
                    $this->log("info", "Moving artifact " . $artifact_name . " to temp...");
                    $file->move(__DIR__ . self::$TEMP_DIR, $artifact_name);
                    if (!file_exists($artifact_path)) {
                        return $this->fail();
                    }
                    $this->success();

                    $this->log("info", "Extracting artifact: " . $artifact_name);
                    $archive = new Archive_Tar($artifact_path);
                    if (!$archive->extract($new_build_path)) {
                        return $this->fail();
                    }
                    $this->success();
                } else {
                    $this->log("info", "Use existing: " . $relative_path);
                    rename($this->config['current_path'] . "/" . $relative_path, $new_build_path . "/" . $relative_path);
                    if (!file_exists($new_build_path . "/" . $relative_path)) {
                        return $this->fail();
                    }
                    $this->success();
                }
            }
        }
        return true;
    }

    private function backup_old_build() {
        $this->log("info", "Backing up old build...");
        if (file_exists($this->config['current_path'])) {
            if (file_exists($this->config['old_path'])) {
                $this->log("info", "Removing old backup...");
                $this->recursive_unlink($this->config['old_path']);
//                if (@rename($this->config['old_path'], __DIR__ . self::$TEMP_DIR . "/" . $this->service . "-backup-" .time())) {
                if (file_exists($this->config['old_path'])) {
                    return $this->fail();
                }
                $this->success();
            }

            $this->log("info", "Attempting fast rename...");
            if (@rename($this->config['current_path'], $this->config['old_path'])) {
                $this->success();
                $this->log("info", "Backup finished!");
                return true;
            }
            $this->fail();

            $this->log("info", "Copying new backup...");
            $this->recurse_copy($this->config['current_path'], $this->config['old_path']);
            if (file_exists($this->config['old_path'])) {
                $this->success();
                $this->log("info", "Backup finished!");
                return true;
            }
            return $this->fail();
        }
        return true;
    }

    private function deploy_new_build($new_build_path) {
        $this->log("info", "Deploying new build...");
        if (file_exists($this->config['current_path'])) {
            $this->log("info", "Removing current build...");
            $this->recursive_unlink($this->config['current_path']);
            if (file_exists($this->config['current_path'])) {
                return $this->fail();
            }
            $this->success();
        }
        $this->log("info", "Attempting fast rename...");
        if (@rename($new_build_path, $this->config['current_path'])) {
            return $this->success();
        }
        $this->fail();
        $this->log("info", "Copying new build...");
        $this->recurse_copy($new_build_path, $this->config['current_path']);
        if (!file_exists($this->config['current_path'])) {
            return $this->fail();
        }
        return $this->success();
    }

    public function finish($success, $new_build_path=null) {
        if ($new_build_path) {
            $this->log("info", "Removing extracted archive");
            $this->recursive_unlink($new_build_path);
            if (file_exists($new_build_path)) {
                return $this->fail();
            }
            $this->success();
        }

        if ($success) {
            $this->log("info", "Deploy finished!");
            return true;
        }
        $this->log("error", "Deploy failed!");
        return true;
    }

    public function deploy($request) {
        if (false === ($artifact_path = $this->move_artifact_to_temp($request)))
            return $this->finish(false);

        if (false === ($new_build_path = $this->extract_artifact($artifact_path)))
            return $this->finish(false);

        if (false === $this->delete_artifact($artifact_path))
            return $this->finish(false, $new_build_path);

//      if (false === $this->add_optional_artifacts($new_build_path, $request))
//          return $this->finish(false, $new_build_path);

        if (false === $this->add_extra_files($new_build_path))
            return $this->finish(false, $new_build_path);

        if (false === $this->backup_old_build())
            return $this->finish(false, $new_build_path);

        if (false === $this->deploy_new_build($new_build_path))
            return $this->finish(false, $new_build_path);

        return $this->finish(true, $new_build_path);
    }
}


$app->post('/deploy/{service}', function(Application $app, Request $request, $service) {
    if (isset($app['config'][$service])) {
        $config = $app['config'][$service];

        $secret = $request->get("secret");
        if ($secret != $config['secret']) {
            return new Response("Forbidden", 403, array('Content-Type' => 'text/plain'));
        }

        $deployer = new Deployer($service, $config);
        $stream = function () use ($deployer, $request) {
            $deployer->deploy($request);
        };

        return $app->stream($stream, 200, array('Content-Type' => 'text/plain'));
    }
    return "ERROR: missing service: " . $service;
});

$app->post('/rollback/{service}', function(Application $app, Request $request, $service) {
    if (isset($app['config'][$service])) {
        $config = $app['config'][$service];

        $secret = $request->get("secret");
        if ($secret != $config['secret']) {
            return new Response("Forbidden", 403, array('Content-Type' => 'text/plain'));
        }

        $deployer = new Deployer($service, $config);
        $deployer->recursive_unlink($config['current_path']);
        if (!@rename($config['old_path'], $config['current_path'])) {
            $deployer->recurse_copy($config['old_path'], $config['current_path']);
        }

        return "Done!";
    }
});

$app->run();
