<?php

use Silex\Application;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/vendor/autoload.php';

// date_default_timezone_set(APP_TIMEZONE);
// setlocale(LC_CTYPE, APP_LOCALE);

$app = new Application();
$app['debug'] = true;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/services/')) as $file) {
    if ($file->isFile() && $file->getExtension() == "yml") {
        $app->register(new DerAlex\Silex\YamlConfigServiceProvider($file->getPathname()));
    }
}

function recursive_unlink($dir) {
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dir);
}

function recurse_copy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recurse_copy($src . '/' . $file,$dst . '/' . $file);
            } else {
                copy($src . '/' . $file,$dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

$app->post('/deploy/{service}', function(Application $app, Request $request, $service) {
    if (isset($app['config'][$service])) {
        $config = $app['config'][$service];

        $secret = $request->get("secret");
        if ($secret != $config['secret']) {
            return new Response("Forbidden", 403, array('Content-Type' => 'text/plain'));
        }

        $file = $request->files->get("artifact");
        if ($file) {
            echo "Archive uploaded\n";

            $archive = new Archive_Tar($file->getPathname());
            $deploy_target = $file->getPathname() . ".deploy";
            if (!file_exists($deploy_target)) mkdir($deploy_target, 0777);
            $archive->extract($deploy_target);
            echo "Archive extracted\n";

            unlink($file->getPathname());
            echo "Archive deleted\n";

            if (!empty($config['extra_files'])) {
                foreach ($config['extra_files'] as $filename) {
                    $extra_file_path = __DIR__ . "/services/" . $service . "/" . $filename;
                    if (file_exists($extra_file_path)) {
                        copy($extra_file_path, $deploy_target . "/" . $filename);
                    } else {
                        echo "WARNING: Missing extra file: " . $filename . "\n";
                    }
                }
                echo "Extra files copied\n";
            }

            if (file_exists($config['current_path'])) {
                echo "Removing old backup: ";
                recursive_unlink($config['old_path']);
                echo "OK\nCopying new backup: ";
                recurse_copy($config['current_path'], $config['old_path']);
                echo "OK\nBackup finished!\n";
            }

            @rename($deploy_target, $config['current_path']);
            echo "New build deployed\n";
        }

        return "Done!";
    }
});

$app->run();
