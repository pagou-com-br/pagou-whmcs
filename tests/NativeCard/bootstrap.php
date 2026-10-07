<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;

$runDir = (string) getenv('PAGOU_NATIVE_RUN_DIR');
$nativeRoot = (string) getenv('PAGOU_WHMCS_ROOT');
if (!preg_match('#^/tmp/pagou-card\.[A-Za-z0-9]+$#', $runDir) || !is_file($runDir . '/mysql.pid')) {
    throw new RuntimeException('Use run.sh to create the isolated database.');
}
define('WHMCS', true);
define('ROOTDIR', $runDir);
date_default_timezone_set('UTC');
// No init.php, configuration.php, customer data or native gateway functions are loaded.
require $nativeRoot . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/package/modules/gateways/pagou/bootstrap.php';
class_alias(WHMCS\Application\Support\Facades\Di::class, 'DI');
$container = new WHMCS\Container();
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->instance('di', $container);
$config = new WHMCS\Config\Application();
$config->setData(['cc_encryption_hash' => str_repeat('synthetic-test-only-', 4)]);
$container->instance('config', $config);
require $nativeRoot . '/includes/functions.php';
$container->instance('runtimeStorage', new WHMCS\Config\RuntimeStorage());
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql', 'unix_socket' => $runDir . '/mysql.sock',
    'database' => 'pagou_card_test', 'username' => 'root', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    'options' => [PDO::ATTR_EMULATE_PREPARES => false],
]);
$capsule->setEventDispatcher(new Illuminate\Events\Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
