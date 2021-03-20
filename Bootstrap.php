<?php declare(strict_types=1);

/*
 * This file is part of the VV package.
 *
 * (c) Volodymyr Sarnytskyi <v00v4n@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace VV;

/**
 * Class Bootstrap
 *
 * @package VV
 */
final class Bootstrap {

    static private ?array $smap = null;

    public static function dfltSysConfig(bool $devMode = false): void {
        if (\VV\ISHTTP) ob_start();

        error_reporting(-1);
        set_time_limit(60);
        mb_internal_encoding(\VV\CHARSET);

        if (!\VV\OS\WIN) umask(07);

        \VV\Bootstrap::iniSet([
            'default_mimetype' => 'text/html',
            'default_charset' => \VV\CHARSET,
            'display_errors' => $devMode ? 'On' : 'Off',
        ]);
    }

    /**
     * Performs ini_set for $set map
     *
     * @param iterable $set Map key=>value
     * @param string   $pfx For example 'session.', 'mbstring.', 'opcache.' ...
     */
    public static function iniSet(iterable $set, $pfx = '') {
        foreach ($set as $k => $v) {
            ini_set($pfx . $k, (string)$v);
        }
    }

    /**
     * Resgisters class autoloader via spl_autoload_register once and updates autoload map.
     * May be called several times. Every invoke merges existent map with passed map
     *
     * @param array $map Array like ['App' => 'app/class/App/', 'Mode' => 'app/mode/', 'app/class/']
     */
    public static function autoload(array $map) {
        if (!self::$smap) {
            //echo "spl_autoload_register\n";
            spl_autoload_register(function ($class_name) {
                $pth = str_replace('\\', '/', $class_name);
                $sfx = null;

                foreach (self::$smap as $ns => $dir) {
                    if (!$dir) continue;
                    // for 'key'=>'value' items
                    if (($isns = is_string($ns)) && !preg_match("!^$ns\\b!", $pth)) continue;
                    // require file if exists
                    if ($fex = file_exists($f = $dir . "$pth.php")) require_once $f;

                    if ($fex || $isns) break;
                }
            });
        }

        self::$smap = array_merge(self::$smap ?: [], $map);
    }

    public static function initErrorHandler() {
        set_error_handler(function ($no, $str, $file, $line) {
            $errorReporting = error_reporting();
            if ($errorReporting == 0) return null;
            // strange behaviour in php 8.0.1 -- error_reporting() == 4437 on @some_error()
            if (PHP_MAJOR_VERSION >= 8 && $errorReporting == 4437) return null;

            static $recurs = 0;
            if ($recurs++ > 1) return true; // only two recursion allowed

            $e = new \VV\Exception\PhpException(self::makePhpErrorMessage($no, $str, $file, $line), $no);

            $recurs--;
            throw $e;
        });
    }

    public static function initExceptionHandler(): void {
        set_exception_handler(function ($e) {
            \VV\Exception::show($e);
        });
    }

    public static function fatalHandler($enable = true): void {
        static $enabled;

        if ($enabled === null) {
            register_shutdown_function(function () use (&$enabled) {
                if (!$enabled) return;

                if ($error = error_get_last()) {
                    $mesage = self::makePhpErrorMessage($error['type'], $error['message'], $error['file'], $error['line']);

                    \VV\Exception::show(new \VV\Exception\PhpException($mesage, $error['type']));
                }

                error_reporting(0);
                session_write_close();
            });
        }

        $enabled = $enable;
    }

    private static function makePhpErrorMessage($no, $str, $file, $line): string {
        if (\VV\OS\WIN) $str = iconv('windows-1251', 'utf-8//IGNORE', $str);
        $str = str_replace('[<a href=\'', '[<a target="_blank" href=\'http://php.net/', $str);

        $ers = [
            E_ERROR | E_USER_ERROR => 'Fatal Error',
            E_WARNING | E_USER_WARNING => 'Warning',
            E_PARSE => 'Parse error',
            E_NOTICE | E_USER_NOTICE => 'Notice',
            E_STRICT => 'Strict Standards',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED | E_USER_DEPRECATED => 'Deprecated',
            0 => "Unknown Error No$no",
        ];
        foreach ($ers as $k => $error) if ($no & $k) break;

        return "<b>PHP $error</b>: $str in " . ideUrl($file, $line, true);
    }
}
