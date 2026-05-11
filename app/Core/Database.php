<?php
declare(strict_types=1);
namespace MyTube\Core;

class Database
{
    private static ?Database $instance = null;
    private object $pdo;

    private function __construct()
    {
        require_once dirname(__DIR__, 2) . '/includes/config.php';
        /** @var \LazyPDO $pdo */
        global $pdo;
        // Unwrap LazyPDO: force a real PDO connection via the prepare proxy
        // so that repositories receive a genuine PDO-compatible object.
        $this->pdo = $pdo; // LazyPDO is PDO-compatible via __call/__get
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @return object  LazyPDO proxy over a real PDO connection */
    public function getConnection(): object
    {
        return $this->pdo;
    }
}
