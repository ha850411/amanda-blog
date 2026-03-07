<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use PDO;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * 建立本機or測試環境的資料庫
 * 測試環境建立指令: `php artisan db:ensure-databases --env=testing`
 */
class EnsureDatabasesExist extends Command
{
    use Prohibitable;

    protected $signature = 'db:ensure-databases
                            {--only-connection= : Only ensure databases for a specific connection}';

    protected $description = 'Ensure all databases exist before running migrations';

    public function handle(): int
    {
        if ($this->isProhibited()) {
            return CommandAlias::FAILURE;
        }

        $onlyConnection = $this->option('only-connection');
        $connections = config('database.connections');

        foreach ($connections as $name => $config) {
            if ($onlyConnection && $name !== $onlyConnection) {
                continue;
            }
            if (isset($config['read'])) {
                $config = array_merge($config, $config['write'] ?? []);
            }
            // 目前僅支援 MySQL
            if (($config['driver'] ?? null) !== 'mysql') {
                $this->warn("Skipping non-MySQL connection: {$name}");
                continue;
            }

            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 3306;
            $username = $config['username'] ?? 'root';
            $password = $config['password'] ?? '';
            $database = $config['database'] ?? null;

            $allowedHosts = array_filter(array_map('trim', explode(',', env('DB_ENSURE_ALLOWED_HOSTS', '127.0.0.1,localhost,mysql,mariadb,host.docker.internal,amanda-blog-mysql,amanda-blog-ci-mysql'))));
            if (!in_array($host, $allowedHosts)) {
                $this->error("❌ Invalid host for connection {$name}: {$host}");
                return CommandAlias::FAILURE;
            }

            if (!$database) {
                $this->warn("No database name specified for connection: {$name}");
                continue;
            }

            try {
                $pdo = new PDO("mysql:host=$host;port=$port", $username, $password);
                $pdo->exec(
                    "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci",
                );
                $this->info("✅ Test DB `{$database}` ensured for connection: {$name}");
            } catch (\Throwable $e) {
                // 若連線或 SQL 錯誤，印出錯誤訊息
                $this->error("❌ Failed to ensure DB for connection {$name}: " . $e->getMessage());
            }
        }

        return CommandAlias::SUCCESS;
    }
}
