<?php
/**
 * Shared wiring for every entry point: loads config, autoloads src/, and builds
 * the service objects. Endpoints just do `$app = Bootstrap::init();`.
 */
class Bootstrap
{
    /** @var array */
    public $cfg;
    /** @var GoogleAuth */  public $auth;
    /** @var GoogleClient */ public $client;
    /** @var Sheets */       public $sheets;
    /** @var Drive */        public $drive;
    /** @var Db */           public $db;

    public static function init(): self
    {
        $b = new self();
        $b->cfg = require __DIR__ . '/../config/app.php';
        date_default_timezone_set($b->cfg['timezone']);
        $b->auth   = new GoogleAuth($b->cfg['service_account'], $b->cfg['scopes'], $b->cfg['token_cache_dir']);
        $b->client = new GoogleClient($b->auth);
        $b->sheets = new Sheets($b->client);
        $b->drive  = new Drive($b->client, $b->cfg);
        return $b;
    }

    /** Lazy DB — not every endpoint needs it. */
    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->cfg['db']);
        }
        return $this->db;
    }

    public static function autoload(): void
    {
        foreach ([
            'GoogleAuth', 'GoogleClient', 'Sheets', 'Drive', 'Db', 'Tracker',
            'ResponseSheet', 'Pms', 'Pdf', 'PmsFpdf',
            'Smtp', 'Mailer', 'Whatsapp', 'NotificationService', 'SubmitService',
        ] as $c) {
            require_once __DIR__ . '/' . $c . '.php';
        }
    }
}
